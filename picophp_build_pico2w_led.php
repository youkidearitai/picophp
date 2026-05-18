#!/usr/bin/env php
<?php
/**
 * picophp_build.php
 *
 * One-command wrapper for the PicoPHP prototype.
 *
 * Host build:
 *   php picophp_build.php blink.pphp
 *   ./build/picophp_host
 *
 * Host build + run:
 *   php picophp_build.php blink.pphp --run
 *
 * Generate a Pico SDK project skeleton:
 *   php picophp_build.php blink.pphp --pico
 *
 * Files expected in the same directory:
 *   picophp_compile_pico2w_led.php
 *   picophp_vm_pico2w_led.c
 */

declare(strict_types=1);

final class BuildError extends Exception {}

function usage(): void {
    fwrite(STDOUT, <<<TXT
Usage:
  php picophp_build.php [options] input.pphp

Options:
  --out DIR       Output directory. Default: build
  --run           Run host binary after successful build
  --pico          Generate Pico SDK project skeleton instead of host binary
  --cc CC         C compiler for host build. Default: cc
  -h, --help      Show this help

Examples:
  php picophp_build.php blink.pphp
  php picophp_build.php blink.pphp --run
  php picophp_build.php blink.pphp --pico --out pico_build_src

TXT);
}

function sh_quote(string $s): string {
    return escapeshellarg($s);
}

/**
 * @param list<string> $cmd
 */
function run_cmd(array $cmd, ?string $cwd = null): void {
    $display = implode(' ', array_map('sh_quote', $cmd));
    fwrite(STDERR, "+ {$display}\n");

    $descriptors = [
        0 => STDIN,
        1 => STDOUT,
        2 => STDERR,
    ];

    $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($proc)) {
        throw new BuildError("failed to start command: {$display}");
    }

    $status = proc_close($proc);
    if ($status !== 0) {
        throw new BuildError("command failed with status {$status}: {$display}");
    }
}

/**
 * @param list<string> $cmd
 */
function capture_cmd(array $cmd, ?string $cwd = null): string {
    $display = implode(' ', array_map('sh_quote', $cmd));
    fwrite(STDERR, "+ {$display}\n");

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => STDERR,
    ];

    $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($proc)) {
        throw new BuildError("failed to start command: {$display}");
    }

    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $status = proc_close($proc);
    if ($status !== 0) {
        throw new BuildError("command failed with status {$status}: {$display}");
    }

    return $out === false ? '' : $out;
}

function require_file(string $path): void {
    if (!is_file($path)) {
        throw new BuildError("required file not found: {$path}");
    }
}

function write_pico_cmake(string $outDir): void {
    $cmake = <<<'CMAKE'
cmake_minimum_required(VERSION 3.13)

include(pico_sdk_import.cmake)

project(picophp_app C CXX ASM)

set(CMAKE_C_STANDARD 11)
set(CMAKE_CXX_STANDARD 17)

pico_sdk_init()

add_executable(picophp_app
    picophp_vm_pico2w_led.c
)

target_compile_definitions(picophp_app PRIVATE
    PICOPHP_ON_PICO
    PICOPHP_USE_PROGRAM_HEADER
)

target_include_directories(picophp_app PRIVATE
    ${CMAKE_CURRENT_LIST_DIR}
)

target_link_libraries(picophp_app
    pico_stdlib
    pico_cyw43_arch_none
    hardware_gpio
    hardware_i2c
    hardware_spi
    m
)

pico_enable_stdio_usb(picophp_app 1)
pico_enable_stdio_uart(picophp_app 0)

pico_add_extra_outputs(picophp_app)
CMAKE;

    file_put_contents($outDir . DIRECTORY_SEPARATOR . 'CMakeLists.txt', $cmake);

    $import = <<<'IMPORT'
# This is the standard Pico SDK import helper.
# Copy the real pico_sdk_import.cmake from your Pico SDK checkout if this file is missing.
#
# Typical source:
#   $PICO_SDK_PATH/external/pico_sdk_import.cmake

if (DEFINED ENV{PICO_SDK_PATH} AND (NOT PICO_SDK_PATH))
    set(PICO_SDK_PATH $ENV{PICO_SDK_PATH})
endif ()

if (NOT PICO_SDK_PATH)
    message(FATAL_ERROR "PICO_SDK_PATH is not set. Example: export PICO_SDK_PATH=$HOME/pico/pico-sdk")
endif ()

include(${PICO_SDK_PATH}/external/pico_sdk_import.cmake)
IMPORT;

    file_put_contents($outDir . DIRECTORY_SEPARATOR . 'pico_sdk_import.cmake', $import);

    $readme = <<<'README'
# PicoPHP Pico SDK skeleton

Build example:

```sh
export PICO_SDK_PATH=$HOME/pico/pico-sdk
mkdir -p build
cd build
cmake -DPICO_BOARD=pico2_w -DPICO_PLATFORM=rp2350 ..
make -j4
```

The output UF2 will be under:

```text
build/picophp_app.uf2
```
README;

    file_put_contents($outDir . DIRECTORY_SEPARATOR . 'README.md', $readme);
}

function main(array $argv): int {
    $root = __DIR__;
    $compiler = $root . DIRECTORY_SEPARATOR . 'picophp_compile_pico2w_led.php';
    $vm = $root . DIRECTORY_SEPARATOR . 'picophp_vm_pico2w_led.c';

    $outDir = 'build';
    $run = false;
    $pico = false;
    $cc = getenv('CC') ?: 'cc';
    $input = null;

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];

        if ($arg === '-h' || $arg === '--help') {
            usage();
            return 0;
        } elseif ($arg === '--run') {
            $run = true;
        } elseif ($arg === '--pico') {
            $pico = true;
        } elseif ($arg === '--out') {
            $i++;
            if ($i >= count($argv)) {
                throw new BuildError('missing value for --out');
            }
            $outDir = $argv[$i];
        } elseif (str_starts_with($arg, '--out=')) {
            $outDir = substr($arg, strlen('--out='));
        } elseif ($arg === '--cc') {
            $i++;
            if ($i >= count($argv)) {
                throw new BuildError('missing value for --cc');
            }
            $cc = $argv[$i];
        } elseif (str_starts_with($arg, '--cc=')) {
            $cc = substr($arg, strlen('--cc='));
        } elseif (str_starts_with($arg, '-')) {
            throw new BuildError("unknown option: {$arg}");
        } else {
            if ($input !== null) {
                throw new BuildError('multiple input files specified');
            }
            $input = $arg;
        }
    }

    if ($input === null) {
        usage();
        return 2;
    }

    require_file($compiler);
    require_file($vm);
    require_file($input);

    if (!is_dir($outDir) && !mkdir($outDir, 0777, true)) {
        throw new BuildError("failed to create output directory: {$outDir}");
    }

    $header = capture_cmd([PHP_BINARY, $compiler, $input]);
    file_put_contents($outDir . DIRECTORY_SEPARATOR . 'program_bytecode.h', $header);

    copy($vm, $outDir . DIRECTORY_SEPARATOR . 'picophp_vm_pico2w_led.c');

    if ($pico) {
        write_pico_cmake($outDir);
        fwrite(STDERR, "\nGenerated Pico SDK project in: {$outDir}\n");
        fwrite(STDERR, "Next:\n");
        fwrite(STDERR, "  cd " . sh_quote($outDir) . "\n");
        fwrite(STDERR, "  export PICO_SDK_PATH=\$HOME/pico/pico-sdk\n");
        fwrite(STDERR, "  mkdir -p build && cd build\n");
        fwrite(STDERR, "  cmake -DPICO_BOARD=pico2_w -DPICO_PLATFORM=rp2350 .. && make -j4\n");
        return 0;
    }

    $exe = $outDir . DIRECTORY_SEPARATOR . 'picophp_host';
    if (PHP_OS_FAMILY === 'Windows') {
        $exe .= '.exe';
    }

    run_cmd([
        $cc,
        '-std=c11',
        '-Wall',
        '-Wextra',
        '-O2',
        '-DPICOPHP_USE_PROGRAM_HEADER',
        '-I' . $outDir,
        $outDir . DIRECTORY_SEPARATOR . 'picophp_vm_pico2w_led.c',
        '-lm',
        '-o',
        $exe,
    ]);

    fwrite(STDERR, "\nBuilt host binary: {$exe}\n");

    if ($run) {
        fwrite(STDERR, "\nRunning:\n");
        run_cmd([$exe]);
    } else {
        fwrite(STDERR, "Run it with:\n  " . sh_quote($exe) . "\n");
    }

    return 0;
}

try {
    exit(main($argv));
} catch (BuildError $e) {
    fwrite(STDERR, "build error: {$e->getMessage()}\n");
    exit(1);
}
