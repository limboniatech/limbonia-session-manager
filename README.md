# limbonia-session-mamager
Manage PHP sessions safely...  Including session locking and spoof prevention.

## Installation
composer require limboniatech/limbonia-session-manager

## Usage

```php
<?php
require 'vendor/autoload.php';

Limbonia\SessionManager::start();
```