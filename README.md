# picophp

Embedded PHP(-like) for Raspberry Pi Pico W2

## Usage

- PHP 8.3 or later
- [Raspberry Pi Pico SDK](https://github.com/raspberrypi/pico-sdk)

### Support

- GPIO
- I2C
- SPI

### Download pico-sdk

```
git clone https://github.com/raspberrypi/pico-sdk.git $HOME/pico/pico-sdk
```

### Using SSD1306

- 3V3 -> SSD1306 VCC
- GND -> SSD1306 GND
- GPIO 6 -> SSD1306 SDA
- GPIO 7 -> SSD1306 SDL

```
php picophp_build_pico2w_i2c.php ssd1306_hello_v2.pphp --pico --out pico2w_ssd1306
cd 'pico2w_ssd1306'
export PICO_SDK_PATH=$HOME/pico/pico-sdk
mkdir -p build && cd build
cmake -DPICO_BOARD=pico2_w -DPICO_PLATFORM=rp2350 .. && make -j4
cp picophp_app.uf2 /Volumes/RP2350/
```

## LICENSE

See LICENSE file.
