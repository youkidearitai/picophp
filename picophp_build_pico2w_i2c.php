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
 *   picophp_compile_require_bitwise_ctrl.php
 *   picophp_vm_pico2w_i2c_bitwise_ctrl.c
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

function write_pico_cmake(string $outDir, bool $usbKeyboard = false, bool $debug = true, string $board = 'pico'): void {
    $picophpUsbKeyboard = $usbKeyboard ? "1" : "0";
    $debugFlag = $debug ? "1" : "0";

    $cmake = <<<'CMAKE'
cmake_minimum_required(VERSION 3.13)

include(pico_sdk_import.cmake)

project(picophp_app C CXX ASM)

set(PICOPHP_BOARD "@PICOPHP_BOARD@")

set(CMAKE_C_STANDARD 11)
set(CMAKE_CXX_STANDARD 17)

pico_sdk_init()

add_executable(picophp_app
    picophp_vm_pico2w_i2c_bitwise_ctrl.c
)

set(PICOPHP_USB_KEYBOARD @PICOPHP_USB_KEYBOARD@)
set(PICOPHP_PROGRAM_HAS_DEBUG_LINES @PICOPHP_PROGRAM_HAS_DEBUG_LINES@)

set(PICOPHP_LIBS
    pico_stdlib
    hardware_gpio
    hardware_i2c
    hardware_adc
    hardware_pwm
)

if(PICOPHP_BOARD STREQUAL "pico_w")
    list(APPEND PICOPHP_LIBS pico_cyw43_arch_none)
endif()

if(PICOPHP_BOARD STREQUAL "pico2_w")
    list(APPEND PICOPHP_LIBS pico_cyw43_arch_none)
endif()

if(PICOPHP_BOARD STREQUAL "pico")
    target_compile_definitions(picophp_app PRIVATE
        PICOPHP_LED_GPIO=25
    )
else()
    target_compile_definitions(picophp_app PRIVATE
        PICOPHP_LED_CYW43=1
    )
endif()

if(PICOPHP_USB_KEYBOARD)
    target_sources(picophp_app PRIVATE
        usb_descriptors.c
    )

    list(APPEND PICOPHP_LIBS tinyusb_device tinyusb_board)

    target_compile_definitions(picophp_app PRIVATE
        PICOPHP_ON_PICO=1
        PICOPHP_USB_KEYBOARD=1
        PICOPHP_USE_PROGRAM_HEADER=1
    )

    target_include_directories(picophp_app PRIVATE
        ${CMAKE_CURRENT_LIST_DIR}
    )

    target_link_libraries(picophp_app
        ${PICOPHP_LIBS}
    )

    pico_enable_stdio_usb(picophp_app 0)
    pico_enable_stdio_uart(picophp_app 1)
else()
    target_compile_definitions(picophp_app PRIVATE
        PICOPHP_ON_PICO
        PICOPHP_USE_PROGRAM_HEADER
    )

    target_include_directories(picophp_app PRIVATE
        ${CMAKE_CURRENT_LIST_DIR}
    )

    list(APPEND PICOPHP_LIBS m)
    target_link_libraries(picophp_app
        ${PICOPHP_LIBS}
    )

    pico_enable_stdio_usb(picophp_app 1)
    pico_enable_stdio_uart(picophp_app 0)
endif()

pico_add_extra_outputs(picophp_app)
CMAKE;

    $cmake = str_replace('@PICOPHP_USB_KEYBOARD@', $picophpUsbKeyboard, $cmake);
    $cmake = str_replace('@PICOPHP_PROGRAM_HAS_DEBUG_LINES@', $debugFlag, $cmake);
    $cmake = str_replace('@PICOPHP_BOARD@', $board, $cmake);
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
cmake -DPICO_BOARD=@PICOPHP_BOARD@ -DPICO_PLATFORM=rp2350 ..
make -j4
```

The output UF2 will be under:

```text
build/picophp_app.uf2
```
README;
    if ($board !== 'pico2_w') {
        str_replace("rp2350", "rp2040", $readme);
    }

    $readme = str_replace('@PICOPHP_BOARD@', $board, $readme);
    file_put_contents($outDir . DIRECTORY_SEPARATOR . 'README.md', $readme);
}

function main(array $argv): int {
    $root = __DIR__;
    $compiler = $root . DIRECTORY_SEPARATOR . 'picophp_compile_pico2w_i2c.php';
    $vm = $root . DIRECTORY_SEPARATOR . 'picophp_vm_pico2w_i2c.c';

    $outDir = 'build';
    $run = false;
    $pico = false;
    $cc = getenv('CC') ?: 'cc';
    $usbInput = false;
    $debug = false;
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
        } elseif (str_starts_with($arg, '--board')) {
            $board = substr($arg, strlen('--board='));
        } elseif ($arg === '--out') {
            $i++;
            if ($i >= count($argv)) {
                throw new BuildError('missing value for --out');
            }
            $outDir = $argv[$i];
        } elseif ($arg === '--debug') {
            $debug = true;
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
        } elseif ($arg === '--usb-keyboard') {
            $usbInput = true;
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

    mkdir($outDir);

    if ($usbInput === true) {
        $usbInput = true;
        touch($outDir . '/tusb_config.h');
        file_put_contents($outDir . '/tusb_config.h', <<<'C'
#ifndef _TUSB_CONFIG_H_
#define _TUSB_CONFIG_H_

#define CFG_TUSB_MCU OPT_MCU_RP2040
#define CFG_TUSB_OS OPT_OS_PICO

#define CFG_TUSB_RHPORT0_MODE (OPT_MODE_DEVICE)

#define CFG_TUD_ENDPOINT0_SIZE 64

#define CFG_TUD_HID 1
#define CFG_TUD_CDC 0
#define CFG_TUD_MSC 0
#define CFG_TUD_MIDI 0
#define CFG_TUD_VENDOR 0

#define CFG_TUD_HID_EP_BUFSIZE 16

#endif
C);

        touch($outDir . '/usb_descriptors.c');
        file_put_contents($outDir . '/usb_descriptors.c', <<<'C'
#include "tusb.h"

enum {
    ITF_NUM_HID,
    ITF_NUM_TOTAL
};

#define EPNUM_HID 0x81

uint8_t const desc_hid_report[] = {
    TUD_HID_REPORT_DESC_KEYBOARD()
};

tusb_desc_device_t const desc_device = {
    .bLength            = sizeof(tusb_desc_device_t),
    .bDescriptorType    = TUSB_DESC_DEVICE,
    .bcdUSB             = 0x0200,

    .bDeviceClass       = 0x00,
    .bDeviceSubClass    = 0x00,
    .bDeviceProtocol    = 0x00,

    .bMaxPacketSize0    = CFG_TUD_ENDPOINT0_SIZE,

    .idVendor           = 0xCafe,
    .idProduct          = 0x4020,
    .bcdDevice          = 0x0100,

    .iManufacturer      = 0x01,
    .iProduct           = 0x02,
    .iSerialNumber      = 0x03,

    .bNumConfigurations = 0x01
};

uint8_t const *tud_descriptor_device_cb(void) {
    return (uint8_t const *)&desc_device;
}

#define CONFIG_TOTAL_LEN (TUD_CONFIG_DESC_LEN + TUD_HID_DESC_LEN)

uint8_t const desc_configuration[] = {
    TUD_CONFIG_DESCRIPTOR(
        1,
        ITF_NUM_TOTAL,
        0,
        CONFIG_TOTAL_LEN,
        TUSB_DESC_CONFIG_ATT_REMOTE_WAKEUP,
        100
    ),

    TUD_HID_DESCRIPTOR(
        ITF_NUM_HID,
        0,
        HID_ITF_PROTOCOL_KEYBOARD,
        sizeof(desc_hid_report),
        EPNUM_HID,
        CFG_TUD_HID_EP_BUFSIZE,
        10
    )
};

uint8_t const *tud_descriptor_configuration_cb(uint8_t index) {
    (void)index;
    return desc_configuration;
}

uint8_t const *tud_hid_descriptor_report_cb(uint8_t instance) {
    (void)instance;
    return desc_hid_report;
}

char const *string_desc_arr[] = {
    (const char[]){ 0x09, 0x04 },
    "PicoPHP",
    "PicoPHP Keyboard",
    "000001",
};

static uint16_t _desc_str[32];

uint16_t const *tud_descriptor_string_cb(uint8_t index, uint16_t langid) {
    (void)langid;

    uint8_t chr_count;

    if (index == 0) {
        memcpy(&_desc_str[1], string_desc_arr[0], 2);
        chr_count = 1;
    } else {
        if (index >= sizeof(string_desc_arr) / sizeof(string_desc_arr[0])) {
            return NULL;
        }

        const char *str = string_desc_arr[index];
        chr_count = (uint8_t)strlen(str);

        if (chr_count > 31) {
            chr_count = 31;
        }

        for (uint8_t i = 0; i < chr_count; i++) {
            _desc_str[1 + i] = str[i];
        }
    }

    _desc_str[0] = (TUSB_DESC_STRING << 8) | (2 * chr_count + 2);
    return _desc_str;
}

void tud_hid_set_report_cb(
    uint8_t instance,
    uint8_t report_id,
    hid_report_type_t report_type,
    uint8_t const *buffer,
    uint16_t bufsize
) {
    (void)instance;
    (void)report_id;
    (void)report_type;
    (void)buffer;
    (void)bufsize;
}

uint16_t tud_hid_get_report_cb(
    uint8_t instance,
    uint8_t report_id,
    hid_report_type_t report_type,
    uint8_t *buffer,
    uint16_t reqlen
) {
    (void)instance;
    (void)report_id;
    (void)report_type;
    (void)buffer;
    (void)reqlen;
    return 0;
}
C);
    }
    if (!is_dir($outDir) && !mkdir($outDir, 0777, true)) {
        throw new BuildError("failed to create output directory: {$outDir}");
    }

    $header = capture_cmd([PHP_BINARY, $compiler, $input]);
    file_put_contents($outDir . DIRECTORY_SEPARATOR . 'program_bytecode.h', $header);

    copy($vm, $outDir . DIRECTORY_SEPARATOR . 'picophp_vm_pico2w_i2c_bitwise_ctrl.c');

    $platform = $board === 'pico2_w' ? 'rp2350' : 'rp2040';

    if ($pico) {
        write_pico_cmake($outDir, $usbInput, board: $board);
        fwrite(STDERR, "\nGenerated Pico SDK project in: {$outDir}\n");
        fwrite(STDERR, "Next:\n");
        fwrite(STDERR, "  cd " . sh_quote($outDir) . "\n");
        fwrite(STDERR, "  export PICO_SDK_PATH=\$HOME/pico/pico-sdk\n");
        fwrite(STDERR, "  mkdir -p build && cd build\n");
        fwrite(STDERR, "  cmake -DPICO_BOARD={$board} -DPICO_PLATFORM={$platform} .. && make -j4\n");
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
        $outDir . DIRECTORY_SEPARATOR . 'picophp_vm_pico2w_i2c_bitwise_ctrl.c',
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
