// picophp_vm_mvp.c
// Minimal PicoPHP-style bytecode VM prototype.
//
// Host build:
//   cc -std=c11 -Wall -Wextra -O2 picophp_vm_mvp.c -lm -o picophp_vm_mvp
//   ./picophp_vm_mvp
//
// With compiler output:
//   python3 picophp_compile.py blink.pphp > program_bytecode.h
//   cc -std=c11 -Wall -Wextra -O2 -DPICOPHP_USE_PROGRAM_HEADER picophp_vm_mvp.c -lm -o picophp_vm_mvp
//
// Pico SDK idea:
//   add_compile_definitions(PICOPHP_ON_PICO PICOPHP_USE_PROGRAM_HEADER)
//   target_sources(app PRIVATE picophp_vm_mvp.c)
//   target_include_directories(app PRIVATE ${CMAKE_CURRENT_LIST_DIR})
//
// Semantics:
//   - Not PHP compatible; PHP-like.
//   - string is immutable binary byte string.
//   - $str[$i] returns int byte 0..255.
//   - int is int32_t.
//   - float is float32.
//   - native functions are numeric IDs resolved by the PC-side compiler.
//   - user functions are bytecode functions with local variables.

#include <stdint.h>
#include <stdbool.h>
#include <stddef.h>
#include <stdio.h>
#include <string.h>
#include <math.h>

#ifdef PICOPHP_ON_PICO
#include "pico/stdlib.h"
#include "hardware/gpio.h"
#include "hardware/i2c.h"
#include "hardware/adc.h"
#include "hardware/pwm.h"
#if __has_include("pico/cyw43_arch.h")
#include "pico/cyw43_arch.h"
#endif
#endif

#ifdef PICOPHP_USB_KEYBOARD
#include "tusb.h"
#include "class/hid/hid.h"
#endif

static void picophp_usb_task(void) {
#ifdef PICOPHP_USB_KEYBOARD
    tud_task();
#endif
}

#ifdef PICOPHP_USB_KEYBOARD

static void keyboard_wait_ready(void) {
    while (!tud_mounted()) {
        tud_task();
        sleep_ms(1);
    }

    while (!tud_hid_ready()) {
        tud_task();
        sleep_ms(1);
    }
}

static void keyboard_send(uint8_t modifier, uint8_t keycode) {
    keyboard_wait_ready();

    uint8_t keys[6] = {0};
    keys[0] = keycode;

    tud_hid_keyboard_report(0, modifier, keys);

    for (int i = 0; i < 5; i++) {
        tud_task();
        sleep_ms(1);
    }

    tud_hid_keyboard_report(0, 0, NULL);

    for (int i = 0; i < 5; i++) {
        tud_task();
        sleep_ms(1);
    }
}

static bool ascii_to_hid(uint8_t ch, uint8_t *modifier, uint8_t *keycode) {
    *modifier = 0;
    *keycode = 0;

    if (ch >= 'a' && ch <= 'z') {
        *keycode = HID_KEY_A + (ch - 'a');
        return true;
    }

    if (ch >= 'A' && ch <= 'Z') {
        *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
        *keycode = HID_KEY_A + (ch - 'A');
        return true;
    }

    if (ch >= '1' && ch <= '9') {
        *keycode = HID_KEY_1 + (ch - '1');
        return true;
    }

    if (ch == '0') {
        *keycode = HID_KEY_0;
        return true;
    }

    switch (ch) {
        case ' ':
            *keycode = HID_KEY_SPACE;
            return true;

        case '\n':
        case '\r':
            *keycode = HID_KEY_ENTER;
            return true;

        case '\t':
            *keycode = HID_KEY_TAB;
            return true;

        case '!':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_1;
            return true;

        case '@':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_2;
            return true;

        case '#':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_3;
            return true;

        case '$':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_4;
            return true;

        case '%':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_5;
            return true;

        case '^':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_6;
            return true;

        case '&':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_7;
            return true;

        case '*':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_8;
            return true;

        case '(':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_9;
            return true;

        case ')':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_0;
            return true;

        case '-':
            *keycode = HID_KEY_MINUS;
            return true;

        case '_':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_MINUS;
            return true;

        case '=':
            *keycode = HID_KEY_EQUAL;
            return true;

        case '+':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_EQUAL;
            return true;

        case '[':
            *keycode = HID_KEY_BRACKET_LEFT;
            return true;

        case ']':
            *keycode = HID_KEY_BRACKET_RIGHT;
            return true;

        case ';':
            *keycode = HID_KEY_SEMICOLON;
            return true;

        case ':':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_SEMICOLON;
            return true;

        case '\'':
            *keycode = HID_KEY_APOSTROPHE;
            return true;

        case '"':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_APOSTROPHE;
            return true;

        case ',':
            *keycode = HID_KEY_COMMA;
            return true;

        case '<':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_COMMA;
            return true;

        case '.':
            *keycode = HID_KEY_PERIOD;
            return true;

        case '>':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_PERIOD;
            return true;

        case '/':
            *keycode = HID_KEY_SLASH;
            return true;

        case '?':
            *modifier = KEYBOARD_MODIFIER_LEFTSHIFT;
            *keycode = HID_KEY_SLASH;
            return true;

        case '\\':
            *keycode = HID_KEY_BACKSLASH;
            return true;

        default:
            return false;
    }
}

#endif

#ifdef PICOPHP_ON_PICO
static void picophp_debug_led_set(bool on) {
#if defined(CYW43_WL_GPIO_LED_PIN)
    cyw43_arch_gpio_put(CYW43_WL_GPIO_LED_PIN, on ? 1 : 0);
#elif defined(PICO_DEFAULT_LED_PIN)
    gpio_put(PICO_DEFAULT_LED_PIN, on ? 1 : 0);
#else
    (void)on;
#endif
}

#ifdef PICOPHP_ON_PICO
static void picophp_boot_blink(int count, int on_ms, int off_ms) {
    for (int i = 0; i < count; i++) {
        picophp_debug_led_set(true);
        sleep_ms(on_ms);
        picophp_debug_led_set(false);
        sleep_ms(off_ms);
    }
}
#endif

#ifndef PICOPHP_STACK_MAX
#define PICOPHP_STACK_MAX       128
#endif

#ifndef PICOPHP_GLOBAL_MAX
#define PICOPHP_GLOBAL_MAX       32
#endif

#ifndef PICOPHP_STRING_ARENA
#define PICOPHP_STRING_ARENA   4096
#endif

#ifndef PICOPHP_CALL_MAX
#define PICOPHP_CALL_MAX         16
#endif

#ifndef PICOPHP_USB_WAIT_MS
#define PICOPHP_USB_WAIT_MS    2000
#endif

typedef enum {
    VAL_NULL = 0,
    VAL_BOOL = 1,
    VAL_INT = 2,
    VAL_FLOAT = 3,
    VAL_STRING = 4,
} ValueType;

#define STR_FLAG_FLASH  0x0001
#define STR_FLAG_ARENA  0x0002
#define STR_FLAG_SLICE  0x0004

typedef struct {
    uint16_t len;
    uint16_t flags;
    const uint8_t *data;
} PhpString;

typedef struct {
    ValueType type;
    union {
        bool b;
        int32_t i;
        float f;
        PhpString s;
    } as;
} Value;

typedef struct {
    uint16_t entry;
    uint8_t arity;
    uint8_t local_count;
} FunctionInfo;

typedef struct {
    size_t return_ip;
    int base;
} CallFrame;

typedef enum {
    OP_HALT = 0,

    OP_CONST = 1,
    OP_NULL = 2,
    OP_TRUE = 3,
    OP_FALSE = 4,

    OP_GET_GLOBAL = 5,
    OP_SET_GLOBAL = 6,
    OP_POP = 7,
    OP_DUP = 8,

    OP_ADD = 9,
    OP_SUB = 10,
    OP_MUL = 11,
    OP_DIV = 12,
    OP_MOD = 13,
    OP_NEG = 14,

    OP_EQ = 15,
    OP_NE = 16,
    OP_LT = 17,
    OP_LE = 18,
    OP_GT = 19,
    OP_GE = 20,

    OP_JMP = 21,
    OP_JMP_IF_FALSE = 22,

    OP_CALL_NATIVE = 23,

    OP_STRLEN = 24,
    OP_STR_INDEX = 25,
    OP_CONCAT = 26,

    OP_GET_LOCAL = 27,
    OP_SET_LOCAL = 28,
    OP_CALL = 29,
    OP_RET = 30,

    OP_BIT_AND = 31,
    OP_BIT_OR = 32,
    OP_BIT_XOR = 33,
    OP_BIT_NOT = 34,
    OP_SHL = 35,
    OP_SHR = 36,

    OP_CONST16 = 37,
} OpCode;

typedef enum {
    NATIVE_PRINT = 0,
    NATIVE_SLEEP_MS = 1,
    NATIVE_GPIO_MODE = 2,
    NATIVE_GPIO_WRITE = 3,
    NATIVE_MILLIS = 4,
    NATIVE_SIN = 5,
    NATIVE_COS = 6,
    NATIVE_TAN = 7,
    NATIVE_SQRT = 8,
    NATIVE_ABS = 9,
    NATIVE_CHR = 10,
    NATIVE_ORD = 11,
    NATIVE_BIN2HEX = 12,
    NATIVE_LED_WRITE = 13,
    NATIVE_I2C_INIT = 14,
    NATIVE_I2C_WRITE = 15,
    NATIVE_I2C_READ = 16,
    NATIVE_I2C_WRITE_READ = 17,
    NATIVE_I2C_SCAN = 18,
    NATIVE_I2C_WRITE_CTRL = 19,
    NATIVE_ARENA_RESET = 20,
    NATIVE_FORMAT_DEC1 = 21,

    NATIVE_KEYBOARD_INIT = 22,
    NATIVE_KEYBOARD_KEY  = 23,
    NATIVE_KEYBOARD_PRESS = 24,
    NATIVE_KEYBOARD_RELEASE = 25,
    NATIVE_KEYBOARD_TYPE = 26,
    NATIVE_KEYBOARD_COMBO = 27,

    NATIVE_ADC_INIT = 28,
    NATIVE_ADC_READ = 29,
    NATIVE_ADC_READ_GPIO = 30,

    NATIVE_PWM_INIT = 31,
    NATIVE_PWM_WRITE = 32,
    NATIVE_PWM_WRITE_PERCENT = 33,
} NativeId;

typedef enum {
    VM_OK = 0,
    VM_ERR_STACK_OVERFLOW,
    VM_ERR_STACK_UNDERFLOW,
    VM_ERR_BAD_OPCODE,
    VM_ERR_BAD_CONST,
    VM_ERR_BAD_GLOBAL,
    VM_ERR_TYPE,
    VM_ERR_DIV_ZERO,
    VM_ERR_STRING_BOUNDS,
    VM_ERR_OOM,
    VM_ERR_BAD_NATIVE,
} VmStatus;

typedef struct {
    const uint8_t *code;
    size_t code_len;
    size_t ip;

    const Value *consts;
    size_t const_count;

    const FunctionInfo *funcs;
    size_t func_count;

    Value stack[PICOPHP_STACK_MAX];
    int sp;

    Value globals[PICOPHP_GLOBAL_MAX];

    CallFrame frames[PICOPHP_CALL_MAX];
    int fp;

    uint8_t string_arena[PICOPHP_STRING_ARENA];
    size_t string_used;

    VmStatus status;
} Vm;

static float value_as_float(Value v);
static bool vm_alloc_string(Vm *vm, uint16_t len, uint8_t **out);

static Value v_null(void) {
    Value v;
    v.type = VAL_NULL;
    v.as.i = 0;
    return v;
}

static Value v_bool(bool x) {
    Value v;
    v.type = VAL_BOOL;
    v.as.b = x;
    return v;
}

static Value v_int(int32_t x) {
    Value v;
    v.type = VAL_INT;
    v.as.i = x;
    return v;
}

static Value v_float(float x) {
    Value v;
    v.type = VAL_FLOAT;
    v.as.f = x;
    return v;
}

static bool value_truthy(Value v) {
    switch (v.type) {
        case VAL_NULL: return false;
        case VAL_BOOL: return v.as.b;
        case VAL_INT: return v.as.i != 0;
        case VAL_FLOAT: return v.as.f != 0.0f;
        case VAL_STRING: return v.as.s.len != 0;
        default: return false;
    }
}

static int32_t value_as_int(Value v) {
    switch (v.type) {
        case VAL_BOOL: return v.as.b ? 1 : 0;
        case VAL_INT: return v.as.i;
        case VAL_FLOAT: return (int32_t)v.as.f;
        default: return 0;
    }
}

static bool value_is_number(Value v) {
    return v.type == VAL_INT || v.type == VAL_FLOAT;
}

static bool adc_gpio_to_channel(int gpio, int *channel) {
    if (gpio >= 26 && gpio <= 29) {
        *channel = gpio - 26;
        return true;
    }

    return false;
}

static bool pwm_gpio_valid(int gpio) {
    return gpio >= 0 && gpio <= 29;
}

static bool native_pwm_init(Vm *vm, int argc, Value *args, Value *ret) {
#ifdef PICOPHP_ON_PICO
    if (argc != 2) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

    if (!value_is_number(args[0]) || !value_is_number(args[1])) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    int gpio = value_as_int(args[0]);
    int freq_hz = value_as_int(args[1]);

    if (!pwm_gpio_valid(gpio) || freq_hz <= 0) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    gpio_set_function((uint)gpio, GPIO_FUNC_PWM);

    uint slice = pwm_gpio_to_slice_num((uint)gpio);

    pwm_config cfg = pwm_get_default_config();

    /*
     * PWM clock = 125MHz on many Pico builds.
     * freq = clock / (clkdiv * (wrap + 1))
     *
     * wrap=65535 fixed:
     * clkdiv = 125000000 / (freq * 65536)
     */
    float div = 125000000.0f / ((float)freq_hz * 65536.0f);

    if (div < 1.0f) {
        div = 1.0f;
    }

    if (div > 255.0f) {
        div = 255.0f;
    }

    pwm_config_set_clkdiv(&cfg, div);
    pwm_config_set_wrap(&cfg, 65535);

    pwm_init(slice, &cfg, true);

    ret->type = VAL_NULL;
    return true;
#else
    (void)vm;
    (void)argc;
    (void)args;
    (void)ret;
    return false;
#endif
}

static bool native_pwm_write(Vm *vm, int argc, Value *args, Value *ret) {
#ifdef PICOPHP_ON_PICO
    if (argc != 2) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

    if (!value_is_number(args[0]) || !value_is_number(args[1])) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    int gpio = value_as_int(args[0]);
    int duty = value_as_int(args[1]);

    if (!pwm_gpio_valid(gpio)) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    if (duty < 0) {
        duty = 0;
    }

    if (duty > 65535) {
        duty = 65535;
    }

    pwm_set_gpio_level((uint)gpio, (uint16_t)duty);

    ret->type = VAL_NULL;
    return true;
#else
    (void)vm;
    (void)argc;
    (void)args;
    (void)ret;
    return false;
#endif
}

static bool native_pwm_write_percent(Vm *vm, int argc, Value *args, Value *ret) {
#ifdef PICOPHP_ON_PICO
    if (argc != 2) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

    if (!value_is_number(args[0]) || !value_is_number(args[1])) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    int gpio = value_as_int(args[0]);
    int percent = value_as_int(args[1]);

    if (!pwm_gpio_valid(gpio)) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    if (percent < 0) {
        percent = 0;
    }

    if (percent > 100) {
        percent = 100;
    }

    int duty = (percent * 65535) / 100;
    pwm_set_gpio_level((uint)gpio, (uint16_t)duty);

    ret->type = VAL_NULL;
    return true;
#else
    (void)vm;
    (void)argc;
    (void)args;
    (void)ret;
    return false;
#endif
}

static bool native_adc_init(Vm *vm, int argc, Value *args, Value *ret) {
#ifdef PICOPHP_ON_PICO
    if (argc != 1) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

    if (!value_is_number(args[0])) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    int gpio = value_as_int(args[0]);
    int channel = 0;

    if (!adc_gpio_to_channel(gpio, &channel)) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    adc_init();
    adc_gpio_init((uint)gpio);

    ret->type = VAL_NULL;
    return true;
#else
    (void)vm;
    (void)argc;
    (void)args;
    (void)ret;
    return false;
#endif
}

static bool native_adc_read(Vm *vm, int argc, Value *args, Value *ret) {
#ifdef PICOPHP_ON_PICO
    if (argc != 1) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

    if (!value_is_number(args[0])) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    int channel = value_as_int(args[0]);

    if (channel < 0 || channel > 4) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    adc_select_input((uint)channel);
    uint16_t raw = adc_read();

    ret->type = VAL_INT;
    ret->as.i = (int32_t)raw;
    return true;
#else
    (void)vm;
    (void)argc;
    (void)args;
    (void)ret;
    return false;
#endif
}

static bool native_adc_read_gpio(Vm *vm, int argc, Value *args, Value *ret) {
#ifdef PICOPHP_ON_PICO
    if (argc != 1) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

    if (!value_is_number(args[0])) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    int gpio = value_as_int(args[0]);
    int channel = 0;

    if (!adc_gpio_to_channel(gpio, &channel)) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    adc_select_input((uint)channel);
    uint16_t raw = adc_read();

    ret->type = VAL_INT;
    ret->as.i = (int32_t)raw;
    return true;
#else
    (void)vm;
    (void)argc;
    (void)args;
    (void)ret;
    return false;
#endif
}

static bool native_format_dec1(Vm *vm, Value arg, Value *ret) {
    /*
     * Format a numeric value scaled by 10.
     *
     * Example:
     *   234     => "23.4"
     *   10132   => "1013.2"
     *   -53     => "-5.3"
     *
     * This avoids doing decimal string formatting in PicoPHP code, where
     * float/int mixing can easily reach CONCAT/CHR type errors.
     */
    float xf = value_as_float(arg);
    int32_t scaled;

    if (xf >= 0.0f) {
        scaled = (int32_t)(xf + 0.5f);
    } else {
        scaled = (int32_t)(xf - 0.5f);
    }

    bool neg = scaled < 0;
    if (neg) {
        scaled = -scaled;
    }

    int32_t whole = scaled / 10;
    int32_t frac = scaled % 10;

    char tmp[24];
    int n = snprintf(
        tmp,
        sizeof(tmp),
        neg ? "-%ld.%ld" : "%ld.%ld",
        (long)whole,
        (long)frac
    );

    if (n < 0 || n >= (int)sizeof(tmp)) {
        vm->status = VM_ERR_OOM;
        return false;
    }

    uint8_t *buf = NULL;
    if (!vm_alloc_string(vm, (uint16_t)n, &buf)) {
        return false;
    }

    memcpy(buf, tmp, (size_t)n);

    ret->type = VAL_STRING;
    ret->as.s.len = (uint16_t)n;
    ret->as.s.flags = STR_FLAG_ARENA;
    ret->as.s.data = buf;
    return true;
}

static bool native_keyboard_init(Vm *vm, int argc, Value *args, Value *ret) {
    (void)args;

    if (argc != 0) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

#ifdef PICOPHP_USB_KEYBOARD
    keyboard_wait_ready();
    ret->type = VAL_NULL;
    return true;
#else
    vm->status = VM_ERR_BAD_NATIVE;
    return false;
#endif
}

static bool native_keyboard_key(Vm *vm, int argc, Value *args, Value *ret) {
    if (argc != 1) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

#ifdef PICOPHP_USB_KEYBOARD
    if (!value_is_number(args[0])) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    int keycode = value_as_int(args[0]);

    if (keycode < 0 || keycode > 255) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    keyboard_send(0, (uint8_t)keycode);

    ret->type = VAL_NULL;
    return true;
#else
    vm->status = VM_ERR_BAD_NATIVE;
    return false;
#endif
}

static bool native_keyboard_press(Vm *vm, int argc, Value *args, Value *ret) {
    if (argc != 2) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

#ifdef PICOPHP_USB_KEYBOARD
    if (!value_is_number(args[0]) || !value_is_number(args[1])) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    int modifier = value_as_int(args[0]);
    int keycode = value_as_int(args[1]);

    if (modifier < 0 || modifier > 255 || keycode < 0 || keycode > 255) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    keyboard_wait_ready();

    uint8_t keys[6] = {0};
    keys[0] = (uint8_t)keycode;

    tud_hid_keyboard_report(0, (uint8_t)modifier, keys);

    ret->type = VAL_NULL;
    return true;
#else
    vm->status = VM_ERR_BAD_NATIVE;
    return false;
#endif
}

static bool native_keyboard_release(Vm *vm, int argc, Value *args, Value *ret) {
    (void)args;

    if (argc != 0) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

#ifdef PICOPHP_USB_KEYBOARD
    keyboard_wait_ready();
    tud_hid_keyboard_report(0, 0, NULL);

    ret->type = VAL_NULL;
    return true;
#else
    vm->status = VM_ERR_BAD_NATIVE;
    return false;
#endif
}

static bool native_keyboard_combo(Vm *vm, int argc, Value *args, Value *ret) {
    if (argc != 2) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

#ifdef PICOPHP_USB_KEYBOARD
    if (!value_is_number(args[0]) || !value_is_number(args[1])) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    int modifier = value_as_int(args[0]);
    int keycode = value_as_int(args[1]);

    if (modifier < 0 || modifier > 255 || keycode < 0 || keycode > 255) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    keyboard_send((uint8_t)modifier, (uint8_t)keycode);

    ret->type = VAL_NULL;
    return true;
#else
    vm->status = VM_ERR_BAD_NATIVE;
    return false;
#endif
}

static bool native_keyboard_type(Vm *vm, int argc, Value *args, Value *ret) {
    if (argc != 1) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

#ifdef PICOPHP_USB_KEYBOARD
    if (args[0].type != VAL_STRING) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    Value s = args[0];

    for (uint16_t i = 0; i < s.as.s.len; i++) {
        uint8_t modifier;
        uint8_t keycode;

        if (ascii_to_hid(s.as.s.data[i], &modifier, &keycode)) {
            keyboard_send(modifier, keycode);
        }

        tud_task();
        sleep_ms(2);
    }

    ret->type = VAL_NULL;
    return true;
#else
    vm->status = VM_ERR_BAD_NATIVE;
    return false;
#endif
}

static float value_as_float(Value v) {
    switch (v.type) {
        case VAL_BOOL: return v.as.b ? 1.0f : 0.0f;
        case VAL_INT: return (float)v.as.i;
        case VAL_FLOAT: return v.as.f;
        default: return 0.0f;
    }
}

static void value_print(Value v) {
    switch (v.type) {
        case VAL_NULL:
            printf("null");
            break;
        case VAL_BOOL:
            printf(v.as.b ? "true" : "false");
            break;
        case VAL_INT:
            printf("%ld", (long)v.as.i);
            break;
        case VAL_FLOAT:
            printf("%g", (double)v.as.f);
            break;
        case VAL_STRING:
            fwrite(v.as.s.data, 1, v.as.s.len, stdout);
            break;
        default:
            printf("<unknown>");
            break;
    }
}

static const char *vm_status_name(VmStatus s) {
    switch (s) {
        case VM_OK: return "OK";
        case VM_ERR_STACK_OVERFLOW: return "stack overflow";
        case VM_ERR_STACK_UNDERFLOW: return "stack underflow";
        case VM_ERR_BAD_OPCODE: return "bad opcode";
        case VM_ERR_BAD_CONST: return "bad constant";
        case VM_ERR_BAD_GLOBAL: return "bad global";
        case VM_ERR_TYPE: return "type error";
        case VM_ERR_DIV_ZERO: return "division by zero";
        case VM_ERR_STRING_BOUNDS: return "string offset out of range";
        case VM_ERR_OOM: return "out of memory";
        case VM_ERR_BAD_NATIVE: return "bad native call";
        default: return "unknown";
    }
}

static void vm_init(
    Vm *vm,
    const uint8_t *code,
    size_t code_len,
    const Value *consts,
    size_t const_count,
    const FunctionInfo *funcs,
    size_t func_count
) {
    memset(vm, 0, sizeof(*vm));
    vm->code = code;
    vm->code_len = code_len;
    vm->consts = consts;
    vm->const_count = const_count;
    vm->funcs = funcs;
    vm->func_count = func_count;
    vm->status = VM_OK;
    for (size_t i = 0; i < PICOPHP_GLOBAL_MAX; i++) {
        vm->globals[i] = v_null();
    }
}

static bool push(Vm *vm, Value v) {
    if (vm->sp >= PICOPHP_STACK_MAX) {
        vm->status = VM_ERR_STACK_OVERFLOW;
        return false;
    }
    vm->stack[vm->sp++] = v;
    return true;
}

static bool pop(Vm *vm, Value *out) {
    if (vm->sp <= 0) {
        vm->status = VM_ERR_STACK_UNDERFLOW;
        return false;
    }
    *out = vm->stack[--vm->sp];
    return true;
}

static bool peek(Vm *vm, Value *out) {
    if (vm->sp <= 0) {
        vm->status = VM_ERR_STACK_UNDERFLOW;
        return false;
    }
    *out = vm->stack[vm->sp - 1];
    return true;
}

static uint8_t read_u8(Vm *vm) {
    if (vm->ip >= vm->code_len) {
        vm->status = VM_ERR_BAD_OPCODE;
        return 0;
    }
    return vm->code[vm->ip++];
}

static uint16_t read_u16(Vm *vm) {
    uint8_t lo = read_u8(vm);
    uint8_t hi = read_u8(vm);
    return (uint16_t)lo | ((uint16_t)hi << 8);
}

static int16_t read_i16(Vm *vm) {
    uint8_t lo = read_u8(vm);
    uint8_t hi = read_u8(vm);
    return (int16_t)((uint16_t)lo | ((uint16_t)hi << 8));
}

static bool vm_alloc_string(Vm *vm, uint16_t len, uint8_t **out) {
    if (vm->string_used + len > PICOPHP_STRING_ARENA) {
        vm->status = VM_ERR_OOM;
        return false;
    }
    *out = &vm->string_arena[vm->string_used];
    vm->string_used += len;
    return true;
}

static bool numeric_binary(Vm *vm, Value a, Value b, OpCode op, Value *out) {
    bool use_float = (a.type == VAL_FLOAT || b.type == VAL_FLOAT || op == OP_DIV);

    if (use_float) {
        float x = value_as_float(a);
        float y = value_as_float(b);

        switch (op) {
            case OP_ADD: *out = v_float(x + y); return true;
            case OP_SUB: *out = v_float(x - y); return true;
            case OP_MUL: *out = v_float(x * y); return true;
            case OP_DIV:
                if (y == 0.0f) {
                    vm->status = VM_ERR_DIV_ZERO;
                    return false;
                }
                *out = v_float(x / y);
                return true;
            default:
                vm->status = VM_ERR_TYPE;
                return false;
        }
    }

    int32_t x = value_as_int(a);
    int32_t y = value_as_int(b);

    switch (op) {
        case OP_ADD: *out = v_int(x + y); return true;
        case OP_SUB: *out = v_int(x - y); return true;
        case OP_MUL: *out = v_int(x * y); return true;
        case OP_MOD:
            if (y == 0) {
                vm->status = VM_ERR_DIV_ZERO;
                return false;
            }
            *out = v_int(x % y);
            return true;
        default:
            vm->status = VM_ERR_TYPE;
            return false;
    }
}


static bool bitwise_binary(Vm *vm, Value a, Value b, OpCode op, Value *out) {
    int32_t x = value_as_int(a);
    int32_t y = value_as_int(b);

    switch (op) {
        case OP_BIT_AND:
            *out = v_int(x & y);
            return true;
        case OP_BIT_OR:
            *out = v_int(x | y);
            return true;
        case OP_BIT_XOR:
            *out = v_int(x ^ y);
            return true;
        case OP_SHL:
            *out = v_int((int32_t)((uint32_t)x << (y & 31)));
            return true;
        case OP_SHR:
            *out = v_int(x >> (y & 31));
            return true;
        default:
            vm->status = VM_ERR_TYPE;
            return false;
    }
}

static bool numeric_compare(Vm *vm, Value a, Value b, OpCode op, Value *out) {
    (void)vm;
    float x = value_as_float(a);
    float y = value_as_float(b);
    bool r;

    switch (op) {
        case OP_EQ: r = x == y; break;
        case OP_NE: r = x != y; break;
        case OP_LT: r = x < y; break;
        case OP_LE: r = x <= y; break;
        case OP_GT: r = x > y; break;
        case OP_GE: r = x >= y; break;
        default: r = false; break;
    }

    *out = v_bool(r);
    return true;
}

static bool string_concat(Vm *vm, Value a, Value b, Value *out) {
    if (a.type != VAL_STRING || b.type != VAL_STRING) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    uint32_t total = (uint32_t)a.as.s.len + (uint32_t)b.as.s.len;
    if (total > UINT16_MAX) {
        vm->status = VM_ERR_OOM;
        return false;
    }

    uint8_t *buf = NULL;
    if (!vm_alloc_string(vm, (uint16_t)total, &buf)) {
        return false;
    }

    memcpy(buf, a.as.s.data, a.as.s.len);
    memcpy(buf + a.as.s.len, b.as.s.data, b.as.s.len);

    out->type = VAL_STRING;
    out->as.s.len = (uint16_t)total;
    out->as.s.flags = STR_FLAG_ARENA;
    out->as.s.data = buf;
    return true;
}

static bool native_chr(Vm *vm, Value arg, Value *out) {
    if (arg.type == VAL_FLOAT) {
        return (int32_t)arg.as.f;
    }

    int32_t x = value_as_int(arg);
    if (x < 0 || x > 255) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    uint8_t *buf = NULL;
    if (!vm_alloc_string(vm, 1, &buf)) {
        return false;
    }

    buf[0] = (uint8_t)x;
    out->type = VAL_STRING;
    out->as.s.len = 1;
    out->as.s.flags = STR_FLAG_ARENA;
    out->as.s.data = buf;
    return true;
}

static bool native_ord(Vm *vm, Value arg, Value *out) {
    if (arg.type != VAL_STRING || arg.as.s.len < 1) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    *out = v_int(arg.as.s.data[0]);
    return true;
}

static bool native_bin2hex(Vm *vm, Value arg, Value *out) {
    static const char hex[] = "0123456789abcdef";

    if (arg.type != VAL_STRING) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    uint32_t total = (uint32_t)arg.as.s.len * 2u;
    if (total > UINT16_MAX) {
        vm->status = VM_ERR_OOM;
        return false;
    }

    uint8_t *buf = NULL;
    if (!vm_alloc_string(vm, (uint16_t)total, &buf)) {
        return false;
    }

    for (uint16_t i = 0; i < arg.as.s.len; i++) {
        uint8_t b = arg.as.s.data[i];
        buf[i * 2] = (uint8_t)hex[b >> 4];
        buf[i * 2 + 1] = (uint8_t)hex[b & 0x0f];
    }

    out->type = VAL_STRING;
    out->as.s.len = (uint16_t)total;
    out->as.s.flags = STR_FLAG_ARENA;
    out->as.s.data = buf;
    return true;
}


static bool native_arena_reset(Vm *vm, Value *ret) {
    /*
     * Reset the temporary string arena.
     *
     * This invalidates every string with STR_FLAG_ARENA.  Constant strings are
     * not stored in this arena and remain valid.
     *
     * Safe usage pattern:
     *   - build temporary strings
     *   - send/use them immediately
     *   - call arena_reset() after the frame/transaction is complete
     *
     * Unsafe:
     *   - store a dynamically concatenated string in a variable/global
     *   - call arena_reset()
     *   - use that string afterwards
     */
    vm->string_used = 0;
    *ret = v_null();
    return true;
}

static void native_sleep_ms(int32_t ms) {
#ifdef PICOPHP_USB_KEYBOARD
    for (int32_t i = 0; i < ms; i++) {
        tud_task();
        sleep_ms(1);
    }
#elif PICOPHP_ON_PICO
    sleep_ms((uint32_t)ms);
#else
    printf("[sleep_ms %ld]\n", (long)ms);
#endif
}

static void native_gpio_mode(int32_t pin, int32_t mode) {
#ifdef PICOPHP_ON_PICO
    gpio_init((uint)pin);
    gpio_set_dir((uint)pin, mode ? GPIO_OUT : GPIO_IN);
#else
    printf("[gpio_mode pin=%ld mode=%ld]\n", (long)pin, (long)mode);
#endif
}

static void native_gpio_write(int32_t pin, int32_t value) {
#ifdef PICOPHP_ON_PICO
    gpio_put((uint)pin, value ? 1 : 0);
#else
    printf("[gpio_write pin=%ld value=%ld]\n", (long)pin, (long)value);
#endif
}

static void native_led_write(int32_t value) {
#ifdef PICOPHP_ON_PICO
#if defined(CYW43_WL_GPIO_LED_PIN)
    cyw43_arch_gpio_put(CYW43_WL_GPIO_LED_PIN, value ? 1 : 0);
#elif defined(PICO_DEFAULT_LED_PIN)
    gpio_put(PICO_DEFAULT_LED_PIN, value ? 1 : 0);
#else
    (void)value;
#endif
#else
    printf("[led_write value=%ld]\n", (long)value);
#endif
}

static i2c_inst_t *native_get_i2c_bus(int32_t bus) {
#ifdef PICOPHP_ON_PICO
    return bus == 0 ? i2c0 : i2c1;
#else
    (void)bus;
    return NULL;
#endif
}

static void native_i2c_init(int32_t bus, int32_t sda, int32_t scl, int32_t baud) {
#ifdef PICOPHP_ON_PICO
    i2c_inst_t *i2c = native_get_i2c_bus(bus);
    i2c_init(i2c, (uint)baud);

    gpio_set_function((uint)sda, GPIO_FUNC_I2C);
    gpio_set_function((uint)scl, GPIO_FUNC_I2C);

    gpio_pull_up((uint)sda);
    gpio_pull_up((uint)scl);
#else
    printf("[i2c_init bus=%ld sda=%ld scl=%ld baud=%ld]\n",
        (long)bus, (long)sda, (long)scl, (long)baud);
#endif
}

static bool native_i2c_write(Vm *vm, Value bus, Value addr, Value data, Value *ret) {
    if (data.type != VAL_STRING) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

#ifdef PICOPHP_ON_PICO
    int r = i2c_write_blocking(
        native_get_i2c_bus(value_as_int(bus)),
        (uint8_t)value_as_int(addr),
        data.as.s.data,
        data.as.s.len,
        false
    );
    if (r < 0) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }
    *ret = v_int(r);
#else
    printf("[i2c_write bus=%ld addr=0x%02lx len=%u]\n",
        (long)value_as_int(bus),
        (long)value_as_int(addr),
        data.as.s.len);
    *ret = v_int((int32_t)data.as.s.len);
#endif

    return true;
}


static bool native_i2c_write_ctrl(Vm *vm, Value bus, Value addr, Value ctrl, Value data, Value *ret) {
    if (data.type != VAL_STRING) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

#ifdef PICOPHP_ON_PICO
    if (data.as.s.len > 255) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    uint8_t buf[256];
    buf[0] = (uint8_t)value_as_int(ctrl);
    if (data.as.s.len > 0) {
        memcpy(buf + 1, data.as.s.data, data.as.s.len);
    }

    int r = i2c_write_blocking(
        native_get_i2c_bus(value_as_int(bus)),
        (uint8_t)value_as_int(addr),
        buf,
        (size_t)data.as.s.len + 1,
        false
    );

    if (r < 0) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

    *ret = v_int(r);
#else
    printf("[i2c_write_ctrl bus=%ld addr=0x%02lx ctrl=0x%02lx len=%u]\n",
        (long)value_as_int(bus),
        (long)value_as_int(addr),
        (long)value_as_int(ctrl),
        data.as.s.len);
    *ret = v_int((int32_t)data.as.s.len + 1);
#endif

    return true;
}

static bool native_i2c_read(Vm *vm, Value bus, Value addr, Value lenv, Value *ret) {
    int32_t len = value_as_int(lenv);
    if (len < 0 || len > UINT16_MAX) {
        vm->status = VM_ERR_TYPE;
        return false;
    }

    uint8_t *buf = NULL;
    if (!vm_alloc_string(vm, (uint16_t)len, &buf)) {
        return false;
    }

#ifdef PICOPHP_ON_PICO
    int r = i2c_read_blocking(
        native_get_i2c_bus(value_as_int(bus)),
        (uint8_t)value_as_int(addr),
        buf,
        (size_t)len,
        false
    );
    if (r < 0) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }
#else
    memset(buf, 0, (size_t)len);
    printf("[i2c_read bus=%ld addr=0x%02lx len=%ld]\n",
        (long)value_as_int(bus),
        (long)value_as_int(addr),
        (long)len);
#endif

    ret->type = VAL_STRING;
    ret->as.s.len = (uint16_t)len;
    ret->as.s.flags = STR_FLAG_ARENA;
    ret->as.s.data = buf;
    return true;
}

static bool native_i2c_write_read(Vm *vm, int argc, Value *args, Value *ret) {
#ifdef PICOPHP_ON_PICO
    if (argc != 4) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

    if ((args[0].type != VAL_INT && args[0].type != VAL_FLOAT) ||
        (args[1].type != VAL_INT && args[1].type != VAL_FLOAT) ||
        args[2].type != VAL_STRING ||
        (args[3].type != VAL_INT && args[3].type != VAL_FLOAT)) {
        printf("[i2c_write_read] type error\n");
        fflush(stdout);
        vm->status = VM_ERR_TYPE;
        return false;
    }

    int bus = value_as_int(args[0]);
    int addr = value_as_int(args[1]);
    Value wv = args[2];
    int read_len = value_as_int(args[3]);

    if (bus < 0 || bus > 1) {
        printf("[i2c_write_read] invalid bus=%d\n", bus);
        fflush(stdout);
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

    if (addr < 0 || addr > 0x7f) {
        printf("[i2c_write_read] invalid addr=0x%02x\n", addr);
        fflush(stdout);
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

    if (read_len < 0 || read_len > 256) {
        printf("[i2c_write_read] invalid read_len=%d\n", read_len);
        fflush(stdout);
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

    if (wv.as.s.len == 0) {
        printf("[i2c_write_read] empty write buffer\n");
        fflush(stdout);
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

    uint8_t *buf = NULL;
    if (!vm_alloc_string(vm, (uint16_t)read_len, &buf)) {
        printf("[i2c_write_read] vm_alloc_string failed len=%d\n", read_len);
        fflush(stdout);
        return false;
    }

    i2c_inst_t *i2c = bus == 0 ? i2c0 : i2c1;

    int wr = i2c_write_blocking(
        i2c,
        (uint8_t)addr,
        wv.as.s.data,
        wv.as.s.len,
        true
    );

    if (wr != (int)wv.as.s.len) {
        printf("[i2c_write_read] write failed expected=%u got=%d\n",
            (unsigned)wv.as.s.len,
            wr
        );
        fflush(stdout);
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

    int rr = i2c_read_blocking(
        i2c,
        (uint8_t)addr,
        buf,
        (size_t)read_len,
        false
    );

    if (rr != read_len) {
        printf("[i2c_write_read] read failed expected=%d got=%d\n",
            read_len,
            rr
        );
        fflush(stdout);
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

    ret->type = VAL_STRING;
    ret->as.s.len = (uint16_t)read_len;
    ret->as.s.flags = STR_FLAG_ARENA;
    ret->as.s.data = buf;

    return true;
#else
    (void)vm;
    (void)argc;
    (void)args;
    (void)ret;
    return false;
#endif
}

static void native_i2c_scan(int32_t bus) {
#ifdef PICOPHP_ON_PICO
    printf("I2C scan bus=%ld\n", (long)bus);

    int found = 0;
    uint8_t rxdata = 0;

    for (int addr = 0x08; addr <= 0x77; addr++) {
        int ret = i2c_read_blocking(
            native_get_i2c_bus(bus),
            (uint8_t)addr,
            &rxdata,
            1,
            false
        );

        if (ret >= 0) {
            printf("  found: 0x%02x (%d)\n", addr, addr);
            found++;
        }
    }

    if (found == 0) {
        printf("  no I2C devices found\n");
    }
#else
    printf("[i2c_scan bus=%ld]\n", (long)bus);
    printf("  host stub: no real I2C bus\n");
#endif
}

static int32_t native_millis(void) {
#ifdef PICOPHP_ON_PICO
    return (int32_t)to_ms_since_boot(get_absolute_time());
#else
    static int32_t fake_ms = 0;
    fake_ms += 16;
    return fake_ms;
#endif
}

static bool call_native(Vm *vm, uint8_t id, uint8_t argc) {
    Value args[4];

    if (argc > 4) {
        vm->status = VM_ERR_BAD_NATIVE;
        return false;
    }

    for (int i = (int)argc - 1; i >= 0; i--) {
        if (!pop(vm, &args[i])) {
            return false;
        }
    }

    Value ret = v_null();

    switch ((NativeId)id) {
        case NATIVE_PRINT:
            for (uint8_t i = 0; i < argc; i++) {
                value_print(args[i]);
            }
            break;

        case NATIVE_SLEEP_MS:
            if (argc != 1) goto bad_arity;
            native_sleep_ms(value_as_int(args[0]));
            break;

        case NATIVE_GPIO_MODE:
            if (argc != 2) goto bad_arity;
            native_gpio_mode(value_as_int(args[0]), value_as_int(args[1]));
            break;

        case NATIVE_GPIO_WRITE:
            if (argc != 2) goto bad_arity;
            native_gpio_write(value_as_int(args[0]), value_as_int(args[1]));
            break;

        case NATIVE_MILLIS:
            if (argc != 0) goto bad_arity;
            ret = v_int(native_millis());
            break;

        case NATIVE_SIN:
            if (argc != 1) goto bad_arity;
            ret = v_float(sinf(value_as_float(args[0])));
            break;

        case NATIVE_COS:
            if (argc != 1) goto bad_arity;
            ret = v_float(cosf(value_as_float(args[0])));
            break;

        case NATIVE_TAN:
            if (argc != 1) goto bad_arity;
            ret = v_float(tanf(value_as_float(args[0])));
            break;

        case NATIVE_SQRT:
            if (argc != 1) goto bad_arity;
            ret = v_float(sqrtf(value_as_float(args[0])));
            break;

        case NATIVE_ABS:
            if (argc != 1) goto bad_arity;
            if (args[0].type == VAL_FLOAT) {
                ret = v_float(fabsf(args[0].as.f));
            } else {
                int32_t x = value_as_int(args[0]);
                ret = v_int(x < 0 ? -x : x);
            }
            break;

        case NATIVE_CHR:
            if (argc != 1) goto bad_arity;
            if (!native_chr(vm, args[0], &ret)) {
                return false;
            }
            break;

        case NATIVE_ORD:
            if (argc != 1) goto bad_arity;
            if (!native_ord(vm, args[0], &ret)) {
                return false;
            }
            break;

        case NATIVE_BIN2HEX:
            if (argc != 1) goto bad_arity;
            if (!native_bin2hex(vm, args[0], &ret)) {
                return false;
            }
            break;

        case NATIVE_LED_WRITE:
            if (argc != 1) goto bad_arity;
            native_led_write(value_as_int(args[0]));
            break;

        case NATIVE_I2C_INIT:
            if (argc != 4) goto bad_arity;
            native_i2c_init(
                value_as_int(args[0]),
                value_as_int(args[1]),
                value_as_int(args[2]),
                value_as_int(args[3])
            );
            break;

        case NATIVE_I2C_WRITE:
            if (argc != 3) goto bad_arity;
            if (!native_i2c_write(vm, args[0], args[1], args[2], &ret)) {
                return false;
            }
            break;

        case NATIVE_I2C_READ:
            if (argc != 3) goto bad_arity;
            if (!native_i2c_read(vm, args[0], args[1], args[2], &ret)) {
                return false;
            }
            break;

        case NATIVE_I2C_WRITE_READ:
            if (argc != 4) goto bad_arity;
            if (!native_i2c_write_read(vm, argc, args, &ret)) {
                return false;
            }
            break;

        case NATIVE_I2C_SCAN:
            if (argc != 1) goto bad_arity;
            native_i2c_scan(value_as_int(args[0]));
            break;

        case NATIVE_I2C_WRITE_CTRL:
            if (argc != 4) goto bad_arity;
            if (!native_i2c_write_ctrl(vm, args[0], args[1], args[2], args[3], &ret)) {
                return false;
            }
            break;

        case NATIVE_ARENA_RESET:
            if (argc != 0) goto bad_arity;
            if (!native_arena_reset(vm, &ret)) {
                return false;
            }
            break;

        case NATIVE_FORMAT_DEC1:
            if (argc != 1) goto bad_arity;
            if (!native_format_dec1(vm, args[0], &ret)) {
                return false;
            }
            break;

        case NATIVE_KEYBOARD_INIT:
            if (!native_keyboard_init(vm, argc, args, &ret)) {
                return false;
            }
            break;

        case NATIVE_KEYBOARD_KEY:
            if (!native_keyboard_key(vm, argc, args, &ret)) {
                return false;
            }
            break;

        case NATIVE_KEYBOARD_PRESS:
            if (!native_keyboard_press(vm, argc, args, &ret)) {
                return false;
            }
            break;

        case NATIVE_KEYBOARD_RELEASE:
            if (!native_keyboard_release(vm, argc, args, &ret)) {
                return false;
            }
            break;

        case NATIVE_KEYBOARD_TYPE:
            if (!native_keyboard_type(vm, argc, args, &ret)) {
                return false;
            }
            break;

        case NATIVE_KEYBOARD_COMBO:
            if (!native_keyboard_combo(vm, argc, args, &ret)) {
                return false;
            }
            break;

        case NATIVE_ADC_INIT:
            if (!native_adc_init(vm, argc, args, &ret)) {
                return false;
            }
            break;

        case NATIVE_ADC_READ:
            if (!native_adc_read(vm, argc, args, &ret)) {
                return false;
            }
            break;

        case NATIVE_ADC_READ_GPIO:
            if (!native_adc_read_gpio(vm, argc, args, &ret)) {
                return false;
            }
            break;

        case NATIVE_PWM_INIT:
            if (!native_pwm_init(vm, argc, args, &ret)) {
                return false;
            }
            break;

        case NATIVE_PWM_WRITE:
            if (!native_pwm_write(vm, argc, args, &ret)) {
                return false;
            }
            break;

        case NATIVE_PWM_WRITE_PERCENT:
            if (!native_pwm_write_percent(vm, argc, args, &ret)) {
                return false;
            }
            break;

        default:
            vm->status = VM_ERR_BAD_NATIVE;
            return false;
    }

    return push(vm, ret);

bad_arity:
    vm->status = VM_ERR_BAD_NATIVE;
    return false;
}

static VmStatus vm_run(Vm *vm) {
    while (vm->status == VM_OK) {
        if (vm->ip >= vm->code_len) {
            vm->status = VM_ERR_BAD_OPCODE;
            break;
        }

        OpCode op = (OpCode)read_u8(vm);

        switch (op) {
            case OP_HALT:
                return VM_OK;

            case OP_CONST: {
                uint8_t id = read_u8(vm);
                if (id >= vm->const_count) {
                    return vm->status = VM_ERR_BAD_CONST;
                }
                if (!push(vm, vm->consts[id])) return vm->status;
                break;
            }

            case OP_CONST16: {
                uint16_t id = read_u16(vm);
                if (id >= vm->const_count) {
                    return vm->status = VM_ERR_BAD_CONST;
                }
                if (!push(vm, vm->consts[id])) return vm->status;
                break;
            }

            case OP_NULL:
                if (!push(vm, v_null())) return vm->status;
                break;

            case OP_TRUE:
                if (!push(vm, v_bool(true))) return vm->status;
                break;

            case OP_FALSE:
                if (!push(vm, v_bool(false))) return vm->status;
                break;

            case OP_GET_GLOBAL: {
                uint8_t slot = read_u8(vm);
                if (slot >= PICOPHP_GLOBAL_MAX) {
                    return vm->status = VM_ERR_BAD_GLOBAL;
                }
                if (!push(vm, vm->globals[slot])) return vm->status;
                break;
            }

            case OP_SET_GLOBAL: {
                uint8_t slot = read_u8(vm);
                if (slot >= PICOPHP_GLOBAL_MAX) {
                    return vm->status = VM_ERR_BAD_GLOBAL;
                }
                Value v;
                if (!peek(vm, &v)) return vm->status;
                vm->globals[slot] = v;
                break;
            }

            case OP_POP: {
                Value unused;
                if (!pop(vm, &unused)) return vm->status;
                break;
            }

            case OP_DUP: {
                Value v;
                if (!peek(vm, &v)) return vm->status;
                if (!push(vm, v)) return vm->status;
                break;
            }

            case OP_ADD:
            case OP_SUB:
            case OP_MUL:
            case OP_DIV:
            case OP_MOD: {
                Value b, a, r;
                if (!pop(vm, &b) || !pop(vm, &a)) return vm->status;
                if (!numeric_binary(vm, a, b, op, &r)) return vm->status;
                if (!push(vm, r)) return vm->status;
                break;
            }

            case OP_NEG: {
                Value a;
                if (!pop(vm, &a)) return vm->status;
                if (a.type == VAL_FLOAT) {
                    if (!push(vm, v_float(-a.as.f))) return vm->status;
                } else {
                    if (!push(vm, v_int(-value_as_int(a)))) return vm->status;
                }
                break;
            }

            case OP_BIT_NOT: {
                Value a;
                if (!pop(vm, &a)) return vm->status;
                if (!push(vm, v_int(~value_as_int(a)))) return vm->status;
                break;
            }

            case OP_BIT_AND:
            case OP_BIT_OR:
            case OP_BIT_XOR:
            case OP_SHL:
            case OP_SHR: {
                Value b, a, r;
                if (!pop(vm, &b) || !pop(vm, &a)) return vm->status;
                if (!bitwise_binary(vm, a, b, op, &r)) return vm->status;
                if (!push(vm, r)) return vm->status;
                break;
            }

            case OP_EQ:
            case OP_NE:
            case OP_LT:
            case OP_LE:
            case OP_GT:
            case OP_GE: {
                Value b, a, r;
                if (!pop(vm, &b) || !pop(vm, &a)) return vm->status;
                if (!numeric_compare(vm, a, b, op, &r)) return vm->status;
                if (!push(vm, r)) return vm->status;
                break;
            }

            case OP_JMP: {
                int16_t off = read_i16(vm);
                vm->ip = (size_t)((int32_t)vm->ip + off);
                break;
            }

            case OP_JMP_IF_FALSE: {
                int16_t off = read_i16(vm);
                Value cond;
                if (!peek(vm, &cond)) return vm->status;
                if (!value_truthy(cond)) {
                    vm->ip = (size_t)((int32_t)vm->ip + off);
                }
                break;
            }

            case OP_CALL_NATIVE: {
                uint8_t id = read_u8(vm);
                uint8_t argc = read_u8(vm);
                if (!call_native(vm, id, argc)) return vm->status;
                break;
            }

            case OP_GET_LOCAL: {
                uint8_t slot = read_u8(vm);
                if (vm->fp <= 0) {
                    return vm->status = VM_ERR_BAD_OPCODE;
                }
                int base = vm->frames[vm->fp - 1].base;
                int idx = base + slot;
                if (idx < 0 || idx >= vm->sp) {
                    return vm->status = VM_ERR_BAD_GLOBAL;
                }
                if (!push(vm, vm->stack[idx])) return vm->status;
                break;
            }

            case OP_SET_LOCAL: {
                uint8_t slot = read_u8(vm);
                if (vm->fp <= 0) {
                    return vm->status = VM_ERR_BAD_OPCODE;
                }
                int base = vm->frames[vm->fp - 1].base;
                int idx = base + slot;
                if (idx < 0 || idx >= vm->sp) {
                    return vm->status = VM_ERR_BAD_GLOBAL;
                }
                Value v;
                if (!peek(vm, &v)) return vm->status;
                vm->stack[idx] = v;
                break;
            }

            case OP_CALL: {
                uint8_t id = read_u8(vm);
                uint8_t argc = read_u8(vm);
                if (id >= vm->func_count || vm->fp >= PICOPHP_CALL_MAX) {
                    return vm->status = VM_ERR_BAD_NATIVE;
                }
                const FunctionInfo *fn = &vm->funcs[id];
                if (argc != fn->arity || vm->sp < argc) {
                    return vm->status = VM_ERR_BAD_NATIVE;
                }
                int base = vm->sp - argc;
                vm->frames[vm->fp++] = (CallFrame){ .return_ip = vm->ip, .base = base };
                while ((vm->sp - base) < fn->local_count) {
                    if (!push(vm, v_null())) return vm->status;
                }
                vm->ip = fn->entry;
                break;
            }

            case OP_RET: {
                if (vm->fp <= 0) {
                    return vm->status = VM_ERR_BAD_OPCODE;
                }
                Value ret;
                if (!pop(vm, &ret)) return vm->status;
                CallFrame frame = vm->frames[--vm->fp];
                vm->sp = frame.base;
                if (!push(vm, ret)) return vm->status;
                vm->ip = frame.return_ip;
                break;
            }

            case OP_STRLEN: {
                Value s;
                if (!pop(vm, &s)) return vm->status;
                if (s.type != VAL_STRING) {
                    return vm->status = VM_ERR_TYPE;
                }
                if (!push(vm, v_int(s.as.s.len))) return vm->status;
                break;
            }

            case OP_STR_INDEX: {
                Value idx, s;
                if (!pop(vm, &idx) || !pop(vm, &s)) return vm->status;
                if (s.type != VAL_STRING) {
                    return vm->status = VM_ERR_TYPE;
                }
                int32_t i = value_as_int(idx);
                if (i < 0 || i >= s.as.s.len) {
                    return vm->status = VM_ERR_STRING_BOUNDS;
                }
                if (!push(vm, v_int((int32_t)s.as.s.data[i]))) return vm->status;
                break;
            }

            case OP_CONCAT: {
                Value b, a, r;
                if (!pop(vm, &b) || !pop(vm, &a)) return vm->status;
                if (!string_concat(vm, a, b, &r)) return vm->status;
                if (!push(vm, r)) return vm->status;
                break;
            }

            default:
                return vm->status = VM_ERR_BAD_OPCODE;
        }
    }

    return vm->status;
}

#ifdef PICOPHP_USE_PROGRAM_HEADER
#include "program_bytecode.h"
#else
static const uint8_t fallback_str_0[] = { 'A', 0x00, 'Z' };
static const uint8_t fallback_str_1[] = "len=";
static const uint8_t fallback_str_2[] = "byte2=";
static const uint8_t fallback_str_3[] = "hex=";
static const uint8_t fallback_str_4[] = "sin=";
static const uint8_t fallback_str_5[] = "\n";

static const Value picophp_program_consts[] = {
    { .type = VAL_STRING, .as.s = { .len = 3, .flags = STR_FLAG_FLASH, .data = fallback_str_0 } },
    { .type = VAL_STRING, .as.s = { .len = 4, .flags = STR_FLAG_FLASH, .data = fallback_str_1 } },
    { .type = VAL_STRING, .as.s = { .len = 6, .flags = STR_FLAG_FLASH, .data = fallback_str_2 } },
    { .type = VAL_STRING, .as.s = { .len = 4, .flags = STR_FLAG_FLASH, .data = fallback_str_3 } },
    { .type = VAL_STRING, .as.s = { .len = 4, .flags = STR_FLAG_FLASH, .data = fallback_str_4 } },
    { .type = VAL_STRING, .as.s = { .len = 1, .flags = STR_FLAG_FLASH, .data = fallback_str_5 } },
    { .type = VAL_FLOAT,  .as.f = 3.1415927f },
    { .type = VAL_FLOAT,  .as.f = 2.0f },
    { .type = VAL_INT,    .as.i = 25 },
    { .type = VAL_INT,    .as.i = 1 },
    { .type = VAL_INT,    .as.i = 0 },
    { .type = VAL_INT,    .as.i = 100 },
    { .type = VAL_INT,    .as.i = 2 },
};
static const unsigned picophp_program_const_count = sizeof(picophp_program_consts) / sizeof(picophp_program_consts[0]);

static const uint8_t picophp_program_code[] = {
    OP_CONST, 0, OP_SET_GLOBAL, 0, OP_POP,

    OP_CONST, 1, OP_GET_GLOBAL, 0, OP_STRLEN, OP_CONST, 5,
    OP_CALL_NATIVE, NATIVE_PRINT, 3, OP_POP,

    OP_CONST, 2, OP_GET_GLOBAL, 0, OP_CONST, 12, OP_STR_INDEX, OP_CONST, 5,
    OP_CALL_NATIVE, NATIVE_PRINT, 3, OP_POP,

    OP_CONST, 3, OP_GET_GLOBAL, 0, OP_CALL_NATIVE, NATIVE_BIN2HEX, 1, OP_CONST, 5,
    OP_CALL_NATIVE, NATIVE_PRINT, 3, OP_POP,

    OP_CONST, 4, OP_CONST, 6, OP_CONST, 7, OP_DIV,
    OP_CALL_NATIVE, NATIVE_SIN, 1, OP_CONST, 5,
    OP_CALL_NATIVE, NATIVE_PRINT, 3, OP_POP,

    OP_CONST, 8, OP_CONST, 9, OP_CALL_NATIVE, NATIVE_GPIO_MODE, 2, OP_POP,
    OP_CONST, 8, OP_CONST, 9, OP_CALL_NATIVE, NATIVE_GPIO_WRITE, 2, OP_POP,
    OP_CONST, 11, OP_CALL_NATIVE, NATIVE_SLEEP_MS, 1, OP_POP,
    OP_CONST, 8, OP_CONST, 10, OP_CALL_NATIVE, NATIVE_GPIO_WRITE, 2, OP_POP,

    OP_HALT,
};
static const unsigned picophp_program_code_len = sizeof(picophp_program_code);

static const FunctionInfo picophp_program_funcs[] = {
};
static const unsigned picophp_program_func_count = 0;
#endif

#ifdef PICOPHP_PROGRAM_HAS_DEBUG_LINES
static const PicoPhpDebugLine *picophp_find_debug_line(size_t ip) {
    const PicoPhpDebugLine *best = NULL;
    for (unsigned i = 0; i < picophp_program_debug_line_count; i++) {
        if ((size_t)picophp_program_debug_lines[i].ip <= ip) {
            best = &picophp_program_debug_lines[i];
        } else {
            break;
        }
    }
    return best;
}

static void picophp_print_fatal_error(VmStatus st, size_t ip) {
    const PicoPhpDebugLine *line = picophp_find_debug_line(ip);
    if (line != NULL && line->file_id < picophp_program_debug_file_count) {
        printf(
            "\nFatal VM error: %s",
            vm_status_name(st)
        );
        printf(
            "\nat %s",
            picophp_program_debug_files[line->file_id]
        );
        printf(
            "\nline:%u\nip=%zu\n",
            (unsigned)line->line,
            ip
        );
        return;
    }
    printf("\nFatal VM error: %s at ip=%zu\n", vm_status_name(st), ip);
}
#else
static void picophp_print_fatal_error(VmStatus st, size_t ip) {
    printf("\nFatal VM error: %s at ip=%zu\n", vm_status_name(st), ip);
}
#endif

int main(void) {
#ifdef PICOPHP_ON_PICO
    stdio_init_all();
    sleep_ms(PICOPHP_USB_WAIT_MS);
    printf("\n[PicoPHP] boot\n");
    fflush(stdout);
#endif

#if defined(CYW43_WL_GPIO_LED_PIN)
    if (cyw43_arch_init()) {
        while (true) {
            printf("[PicoPHP] cyw43_arch_init failed\n");
            fflush(stdout);
            sleep_ms(1000);
        }
    }
#elif defined(PICO_DEFAULT_LED_PIN)
    gpio_init(PICO_DEFAULT_LED_PIN);
    gpio_set_dir(PICO_DEFAULT_LED_PIN, GPIO_OUT);
#endif
    printf("[PicoPHP] platform init ok\n");
    fflush(stdout);
#endif

#ifdef PICOPHP_ON_PICO
    printf("[PicoPHP] boot blink before vm_init\n");
    fflush(stdout);
    picophp_boot_blink(5, 120, 120);
#endif

    Vm vm;
    vm_init(
        &vm,
        picophp_program_code,
        picophp_program_code_len,
        picophp_program_consts,
        picophp_program_const_count,
        picophp_program_funcs,
        picophp_program_func_count
    );

#ifdef PICOPHP_ON_PICO
    printf("[PicoPHP] entering vm_run\n");
    fflush(stdout);
    picophp_boot_blink(2, 300, 150);
#endif

    VmStatus st = vm_run(&vm);
#ifdef PICOPHP_ON_PICO
#if defined(CYW43_WL_GPIO_LED_PIN)
    cyw43_arch_deinit();
#endif
#endif

    if (st != VM_OK) {
#ifdef PICOPHP_ON_PICO
        while (true) {
            picophp_print_fatal_error(st, vm.ip);
            fflush(stdout);
            picophp_debug_led_set(true);
            sleep_ms(150);
            picophp_debug_led_set(false);
            sleep_ms(850);
        }
#else
        picophp_print_fatal_error(st, vm.ip);
        return 1;
#endif
    }

#ifdef PICOPHP_ON_PICO
    while (true) {
        printf("[PicoPHP] program finished normally\n");
        fflush(stdout);
        picophp_debug_led_set(true);
        sleep_ms(500);
        picophp_debug_led_set(false);
        sleep_ms(500);
    }
#endif

    return 0;
}
