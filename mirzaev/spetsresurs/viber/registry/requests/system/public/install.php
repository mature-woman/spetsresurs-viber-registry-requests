<?php

// Фреймворк для Viber API
use Viber\Client;

require __DIR__ . '/../../../../../../../vendor/autoload.php';

try {
    $client = new Client(['token' => require('../settings/key.php')]);
    $result = $client->setWebhook(require('../settings/url.php'));
    echo "Установлено!\n";
} catch (Exception $e) {
    echo 'Ошибка: ' . $e->getMessage() . "\n";
}
