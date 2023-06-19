<?php

// Фреймворк ArangoDB
use mirzaev\arangodb\connection,
	mirzaev\arangodb\collection,
	mirzaev\arangodb\document;

// Библиотека для ArangoDB
use ArangoDBClient\Document as _document,
	ArangoDBClient\Cursor,
	ArangoDBClient\Statement as _statement;

// Фреймворк для Viber API
use Viber\Bot,
	Viber\Api\Sender,
	Viber\Api\Event,
	Viber\Api\Keyboard,
	Viber\Api\Keyboard\Button,
	Viber\Api\Message\Contact,
	Viber\Api\Event\Message,
	Viber\Api\Message\Text;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

require __DIR__ . '/../../../../../../../vendor/autoload.php';

$arangodb = new connection(require '../settings/arangodb.php');

$botSender = new Sender([
	'name' => 'Requests register',
	'avatar' => 'https://developers.viber.com/img/favicon.ico',
]);

$log = new Logger('bot');
$log->pushHandler(new StreamHandler('../logs/robot.txt'));

/**
 * Авторизация
 *
 * @param string $id Идентификатор Viber
 *
 * @return _document|null|false (инстанция аккаунта, если подключен и авторизован; null, если не подключен; false, если подключен но неавторизован)
 */
function authorization(string $id): _document|null|false
{
	global $arangodb;

	if (collection::init($arangodb->session, 'viber'))
		if (
			($viber = collection::search($arangodb->session, sprintf("FOR d IN viber FILTER d.id == '%s' RETURN d", $id)))
			|| $viber = collection::search(
				$arangodb->session,
				sprintf(
					"FOR d IN viber FILTER d._id == '%s' RETURN d",
					document::write($arangodb->session,	'viber', ['id' => $id, 'status' => 'inactive'])
				)
			)
		)
			if ($viber->number === null) return null;
			else if (
				$viber->status === 'active'
				&& collection::init($arangodb->session, 'workers')
				&& $worker = collection::search(
					$arangodb->session,
					sprintf(
						"FOR d IN workers LET e = (FOR e IN connections FILTER e._to == '%s' RETURN e._from)[0] FILTER d._id == e RETURN d",
						$viber->getId()
					)
				)
			) return $worker;
			else return false;
		else  throw new exception('Не удалось найти или создать аккаунт');
	else throw new exception('Не удалось инициализировать коллекцию');

	return false;
}

function registration(string $id, string $number): bool
{
	global $arangodb;

	if (collection::init($arangodb->session, 'viber')) {
		// Инициализация аккаунта
		if ($viber = collection::search($arangodb->session, sprintf("FOR d IN viber FILTER d.id == '%s' RETURN d", $id))) {
			// Запись номера
			$viber->number = $number;
			if (!document::update($arangodb->session, $viber)) return false;
		} else if (!collection::search(
			$arangodb->session,
			sprintf(
				"FOR d IN viber FILTER d._id == '%s' RETURN d",
				document::write($arangodb->session,	'viber', ['id' => $id, 'status' => 'inactive', 'number' => $number])
			)
		)) return false;

		// Инициализация ребра: workers -> viber
		if (collection::init($arangodb->session, 'workers')
			&& ($worker = collection::search(
				$arangodb->session,
				sprintf(
					"FOR d IN workers FILTER d.phone == '%d' RETURN d",
					$viber->number
				)
			))
			&& collection::init($arangodb->session, 'connections', true)
			&& (collection::search(
				$arangodb->session,
				sprintf(
					"FOR d IN connections FILTER d._from == '%s' && d._to == '%s' RETURN d",
					$worker->getId(),
					$viber->getId()
				)
			)
				?? collection::search(
					$arangodb->session,
					sprintf(
						"FOR d IN connections FILTER d._id == '%s' RETURN d",
						document::write(
							$arangodb->session,
							'connections',
							['_from' => $worker->getId(), '_to' => $viber->getId()]
						)
					)
				))
		) {
			// Инициализировано ребро: workers -> viber

			// Активация
			$viber->status = 'active';
			return document::update($arangodb->session, $viber);
		}
	} else throw new exception('Не удалось инициализировать коллекцию');

	return false;
}

function generateMenuKeyboard(): Keyboard
{
	return (new Keyboard())
		->setButtons([
			(new Button())
				->setBgColor('#97d446')
				->setActionType('reply')
				->setActionBody('btn-search')
				->setText('🔍 Активные заявки')
		]);
}

function generateNumberKeyboard(): Keyboard
{
	return (new Keyboard())
		->setButtons([
			(new Button())
				->setBgColor('#E9003A')
				->setTextSize('large')
				->setTextHAlign('center')
				->setActionType('share-phone')
				->setActionBody('reply')
				->setText('🔐 Аутентификация'),
		]);
}

function requests(int $amount = 5): Cursor
{
	global $arangodb;

	return (new _statement(
		$arangodb->session,
		[
			'query' => sprintf(
				"FOR d IN works FILTER d.confirmed != 'да' LIMIT %d RETURN d",
				$amount
			),
			"batchSize" => 1000,
			"sanitize"  => true
		]
	))->execute();
}

try {
	$bot = new Bot(['token' => require('../settings/key.php')]);

	$bot
		->onText('|btn-request-choose-*|s', function ($event) use ($bot, $botSender, $log) {
			global $arangodb;

			$id = $event->getSender()->getId();

			if (($worker = authorization($id)) instanceof _document) {
				// Авторизован

				// Инициализация ключа инстанции works в базе данных
				preg_match('/btn-request-choose-(\d+)/', $event->getMessage()->getText(), $matches);
				$_key = $matches[1];

				// Инициализация инстанции works в базе данных (выбранного задания)
				$work = collection::search($arangodb->session, sprintf("FOR d IN works FILTER d._key == '%s' RETURN d", $_key));

				// Запись о том, что задание подтверждено (в будущем здесь будет отправка на потдверждение модераторам)
				$work->confirmed = 'да';

				// Запись о том, что необходимо перенести изменения в Google Sheets
				$work->transfer_to_sheets = 'да';

				// Запись идентификатора Google Sheets нового сотрудника
				$work->worker = $worker->id;

				if (document::update($arangodb->session, $work)) {
					// Записано обновление в базу данных

					if (collection::search(
							$arangodb->session,
							sprintf(
								"FOR d IN readinesses FILTER d._id == '%s' RETURN d",
								document::write($arangodb->session, 'readinesses', ['_from' => $worker->getId(), '_to' => $work->getId()])
							)
						)
					) {
						// Записано ребро: worker -> work (принятие заявки)

						$bot->getClient()->sendMessage(
							(new Text())
								->setSender($botSender)
								->setReceiver($id)
								->setText("✅ **Заявка принята:** #$_key")
								->setKeyboard(generateMenuKeyboard())
						);
					}
				}
			} else if ($worker === null) {
				// Не подключен

				$bot->getClient()->sendMessage(
					(new Text())
						->setSender($botSender)
						->setReceiver($id)
						->setMinApiVersion(3)
						->setText('⚠️ **Вы не подключили аккаунт**')
						->setKeyboard(generateNumberKeyboard($event))
				);
			} else {
				// Не авторизован

				$bot->getClient()->sendMessage(
					(new Text())
						->setSender($botSender)
						->setReceiver($id)
						->setText('⛔️ **Вы не авторизованы**')
				);
			}
		})
		->onText('|btn-search|s', function ($event) use ($bot, $botSender) {
			global $arangodb;

			$id = $event->getSender()->getId();

			if (($worker = authorization($id)) instanceof _document) {
				// Авторизован

				$keyboard = [];

				$requests = requests(5);

				if ($requests->getCount() < 1) {
					$bot->getClient()->sendMessage(
						(new Text())
							->setSender($botSender)
							->setReceiver($id)
							->setText("📦 **Заявок нет**")
					);

					return;
				}

				foreach ($requests as $request) {
					// Перебор найденных заявок

					if (($market = collection::search(
						$arangodb->session,
						sprintf(
							"FOR d IN markets LET e = (FOR e IN requests FILTER e._to == '%s' RETURN e._from)[0] FILTER d._id == e RETURN d",
							$request->getId()
						)
					)) instanceof _document) {
						// Найден магазин	

						// Отправка сообщения с данной заявки
						$bot->getClient()->sendMessage(
							(new Text())
								->setSender($botSender)
								->setReceiver($id)
								->setText("**#{$request->getKey()}**\n\n" . $request->date['converted'] . " (" . $request->start['converted'] . " - " . $request->end['converted'] . ")\n**Работа:** \"$request->work\"\n\n**Город:** $market->city\n**Адрес:** $market->address")
						);

						// Запись выбора заявки в клавиатуру
						$keyboard[] = (new Button())
							->setBgColor(sprintf("#%02x%02x%02x", mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)))
							->setTextSize('small')
							->setActionType('reply')
							->setActionBody("btn-request-choose-{$request->getKey()}")
							->setText("#{$request->getKey()}");
					}
				}

				$bot->getClient()->sendMessage(
					(new Text())
						->setSender($botSender)
						->setReceiver($id)
						->setMinApiVersion(3)
						->setText("🔍 Выберите заявку")
						->setKeyboard((new Keyboard())->setButtons($keyboard ?? []))
				);
			} else if ($worker === null) {
				// Не подключен

				$bot->getClient()->sendMessage(
					(new Text())
						->setSender($botSender)
						->setReceiver($id)
						->setMinApiVersion(3)
						->setText('⚠️ **Вы не подключили аккаунт**')
						->setKeyboard(generateNumberKeyboard($event))
				);
			} else {
				// Не авторизован

				$bot->getClient()->sendMessage(
					(new Text())
						->setSender($botSender)
						->setReceiver($id)
						->setText('⛔ **Вы не авторизованы**')
				);
			}
		})
		->onText('|.*|si', function ($event) use ($bot, $botSender) {
			$id = $event->getSender()->getId();

			if (($worker = authorization($id)) instanceof _document) {
				// Авторизован

				$bot->getClient()->sendMessage(
					(new Text())
						->setSender($botSender)
						->setReceiver($id)
						->setText('👋 Здравствуйте, ' . $worker->name)
						->setKeyboard(generateMenuKeyboard($event))
				);
			} else if ($worker === null) {
				// Не подключен

				$bot->getClient()->sendMessage(
					(new Text())
						->setSender($botSender)
						->setReceiver($id)
						->setMinApiVersion(3)
						->setText('⚠️ **Вы не подключили аккаунт**')
						->setKeyboard(generateNumberKeyboard($event))
				);
			} else {
				// Не авторизован

				$bot->getClient()->sendMessage(
					(new Text())
						->setSender($botSender)
						->setReceiver($id)
						->setText('⛔ **Вы не авторизованы**')
				);
			}
		})
		->onConversation(function ($event) use ($bot, $botSender) {
			$id = $event->getUser()->getId();

			if (($worker = authorization($id)) instanceof _document) {
				// Авторизован

				$bot->getClient()->sendMessage(
					(new Text())
						->setSender($botSender)
						->setReceiver($id)
						->setText('👋 Здравствуйте, ' . $worker->name)
						->setKeyboard(generateMenuKeyboard($event))
				);
			} else if ($worker === null) {
				// Не подключен

				$bot->getClient()->sendMessage(
					(new Text())
						->setSender($botSender)
						->setReceiver($id)
						->setMinApiVersion(3)
						->setText('⚠️ **Вы не подключили аккаунт**')
						->setKeyboard(generateNumberKeyboard($event))
				);
			} else {
				// Не авторизован

				$bot->getClient()->sendMessage(
					(new Text())
						->setSender($botSender)
						->setReceiver($id)
						->setText('⛔ **Вы не авторизованы**')
				);
			}
		})
		->onSubscribe(function ($event) use ($bot, $botSender) {
			$id = $event->getUser()->getId();

			if (($worker = authorization($id)) instanceof _document) {
				// Авторизован

				$bot->getClient()->sendMessage(
					(new Text())
						->setSender($botSender)
						->setReceiver($id)
						->setText('Здравствуйте, ' . $worker->name)
						->setKeyboard(generateMenuKeyboard($event))
				);
			} else if ($worker === null) {
				// Не подключен

				$bot->getClient()->sendMessage(
					(new Text())
						->setSender($botSender)
						->setReceiver($id)
						->setMinApiVersion(3)
						->setText('⚠️ **Вы не подключили аккаунт**')
						->setKeyboard(generateNumberKeyboard($event))
				);
			} else {
				// Не авторизован

				$bot->getClient()->sendMessage(
					(new Text())
						->setSender($botSender)
						->setReceiver($id)
						->setText('⛔ **Вы не авторизованы**')
				);
			}
		})
		->on(function ($event) {
			return ($event instanceof Message && $event->getMessage() instanceof Contact);
		}, function ($event) use ($bot, $botSender, $log) {
			$id = $event->getSender()->getId();

			if (registration($id, $event->getMessage()->getPhoneNumber())) {
				// Зарегистрирован

				$bot->getClient()->sendMessage(
					(new Text())
						->setSender($botSender)
						->setReceiver($id)
						->setText('✅ **Аккаунт подключен**')
				);

				if ($worker = authorization($id)) {
					// Авторизован

					$bot->getClient()->sendMessage(
						(new Text())
							->setSender($botSender)
							->setReceiver($id)
							->setText('👋 Здравствуйте, ' . $worker->name)
							->setKeyboard(generateMenuKeyboard($event))
					);
				} else {
					// Не авторизован

					$bot->getClient()->sendMessage(
						(new Text())
							->setSender($botSender)
							->setReceiver($id)
							->setText('⛔ **Вы не авторизованы**')
					);
				}
			} else {
				// Не зарегистрирован

				$bot->getClient()->sendMessage(
					(new Text())
						->setSender($botSender)
						->setReceiver($id)
						->setText('⛔ **Вы не авторизованы**')
				);
			}
		})
		->run();
} catch (Exception $e) {
	$log->warning('Exception: ' . $e->getMessage());
	if ($bot) {
		$log->warning('Actual sign: ' . $bot->getSignHeaderValue());
		$log->warning('Actual body: ' . $bot->getInputBody());
	}
}
