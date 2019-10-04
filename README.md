# MyTOP-php

Simple test to imitate mytop mysql utility writte in php. This is just a test and backup repository, so it's not usable in production.

## Building
```
composer build
```

## Usage
All credentials are parsed from **.my.conf** or **.mytop** and can be overwritten with commadn line options:
```
mytop.phar --user={username} --password={password} --port={port} --host={host}
```