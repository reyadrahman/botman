<?php

namespace Mpociot\BotMan;

use Closure;
use UnexpectedValueException;
use Illuminate\Support\Collection;
use Mpociot\BotMan\Attachments\File;
use Mpociot\BotMan\Messages\Matcher;
use Mpociot\BotMan\Attachments\Audio;
use Mpociot\BotMan\Attachments\Image;
use Mpociot\BotMan\Attachments\Video;
use Mpociot\BotMan\Attachments\Location;
use Mpociot\BotMan\Traits\ProvidesStorage;
use Mpociot\BotMan\Traits\VerifiesServices;
use Mpociot\BotMan\Interfaces\UserInterface;
use Mpociot\BotMan\Messages\MatchingMessage;
use Mpociot\BotMan\Interfaces\CacheInterface;
use Mpociot\BotMan\Interfaces\DriverInterface;
use Mpociot\BotMan\Interfaces\StorageInterface;
use Mpociot\BotMan\Traits\HandlesConversations;
use Mpociot\BotMan\Middleware\MiddlewareManager;
use Mpociot\BotMan\Interfaces\DriverEventInterface;
use Mpociot\BotMan\Conversations\InlineConversation;
use Mpociot\BotMan\Messages\Message as OutgoingMessage;

/**
 * Class BotMan.
 */
class BotMan
{
    use VerifiesServices,
        ProvidesStorage,
        HandlesConversations;

    /** @var \Illuminate\Support\Collection */
    protected $event;

    /** @var Command */
    protected $command;

    /** @var Message */
    protected $message;

    /** @var string */
    protected $driverName;

    /** @var array|null */
    protected $currentConversationData;

    /**
     * Messages to listen to.
     * @var Command[]
     */
    protected $listenTo = [];

    /**
     * Message service events.
     * @var array
     */
    protected $events = [];

    /**
     * The fallback message to use, if no match
     * could be heard.
     * @var callable|null
     */
    protected $fallbackMessage;

    /** @var array */
    protected $groupAttributes = [];

    /** @var array */
    protected $matches = [];

    /** @var DriverInterface */
    protected $driver;

    /** @var array */
    protected $config = [];

    /** @var MiddlewareManager */
    public $middleware;

    /** @var CacheInterface */
    private $cache;

    /** @var StorageInterface */
    protected $storage;

    /** @var Matcher */
    protected $matcher;

    /** @var bool */
    protected $loadedConversation = false;

    /**
     * BotMan constructor.
     * @param CacheInterface $cache
     * @param DriverInterface $driver
     * @param array $config
     * @param StorageInterface $storage
     */
    public function __construct(CacheInterface $cache, DriverInterface $driver, $config, StorageInterface $storage)
    {
        $this->cache = $cache;
        $this->message = new Message('', '', '');
        $this->driver = $driver;
        $this->config = $config;
        $this->storage = $storage;
        $this->matcher = new Matcher();
        $this->middleware = new MiddlewareManager($this);
    }

    /**
     * Set a fallback message to use if no listener matches.
     *
     * @param callable $callback
     */
    public function fallback($callback)
    {
        $this->fallbackMessage = $callback;
    }

    /**
     * @param string $name The Driver name or class
     */
    public function loadDriver($name)
    {
        $this->driver = DriverManager::loadFromName($name, $this->config);
    }

    /**
     * @param DriverInterface $driver
     */
    public function setDriver(DriverInterface $driver)
    {
        $this->driver = $driver;
    }

    /**
     * @return DriverInterface
     */
    public function getDriver()
    {
        return $this->driver;
    }

    /**
     * Retrieve the chat message.
     *
     * @return array
     */
    public function getMessages()
    {
        return $this->getDriver()->getMessages();
    }

    /**
     * @return Answer
     */
    public function getConversationAnswer()
    {
        return $this->getDriver()->getConversationAnswer($this->message);
    }

    /**
     * @return bool
     */
    public function isBot()
    {
        return $this->getDriver()->isBot();
    }

    /**
     * @return UserInterface
     */
    public function getUser()
    {
        return $this->getDriver()->getUser($this->getMessage());
    }

    /**
     * Get the parameter names for the route.
     *
     * @param $value
     * @return array
     */
    protected function compileParameterNames($value)
    {
        preg_match_all(Matcher::PARAM_NAME_REGEX, $value, $matches);

        return array_map(function ($m) {
            return trim($m, '?');
        }, $matches[1]);
    }

    /**
     * @param string $pattern the pattern to listen for
     * @param Closure|string $callback the callback to execute. Either a closure or a Class@method notation
     * @param string $in the channel type to listen to (either direct message or public channel)
     * @return Command
     */
    public function hears($pattern, $callback, $in = null)
    {
        $command = new Command($pattern, $callback, $in);
        $command->applyGroupAttributes($this->groupAttributes);

        $this->listenTo[] = $command;

        return $command;
    }

    /**
     * Listen for messaging service events.
     *
     * @param string $name
     * @param Closure $closure
     */
    public function on($name, Closure $closure)
    {
        $this->events[] = compact('name', 'closure');
    }

    /**
     * Listening for image files.
     *
     * @param $callback
     * @return Command
     */
    public function receivesImages($callback)
    {
        return $this->hears(Image::PATTERN, $callback);
    }

    /**
     * Listening for image files.
     *
     * @param $callback
     * @return Command
     */
    public function receivesVideos($callback)
    {
        return $this->hears(Video::PATTERN, $callback);
    }

    /**
     * Listening for audio files.
     *
     * @param $callback
     * @return Command
     */
    public function receivesAudio($callback)
    {
        return $this->hears(Audio::PATTERN, $callback);
    }

    /**
     * Listening for location attachment.
     *
     * @param $callback
     * @return Command
     */
    public function receivesLocation($callback)
    {
        return $this->hears(Location::PATTERN, $callback);
    }

    /**
     * Listening for files attachment.
     *
     * @param $callback
     * @return Command
     */
    public function receivesFiles($callback)
    {
        return $this->hears(File::PATTERN, $callback);
    }

    /**
     * Add additional data (image,video,audio,location,files) data to
     * callable parameters.
     *
     * @param Message $message
     * @param array $parameters
     * @return array
     */
    private function addDataParameters(Message $message, array $parameters)
    {
        $messageText = $message->getText();

        if ($messageText === Image::PATTERN) {
            $parameters[] = $message->getImages();
        } elseif ($messageText === Video::PATTERN) {
            $parameters[] = $message->getVideos();
        } elseif ($messageText === Audio::PATTERN) {
            $parameters[] = $message->getAudio();
        } elseif ($messageText === Location::PATTERN) {
            $parameters[] = $message->getLocation();
        } elseif ($messageText === File::PATTERN) {
            $parameters[] = $message->getFiles();
        }

        return $parameters;
    }

    /**
     * Create a command group with shared attributes.
     *
     * @param  array $attributes
     * @param  \Closure $callback
     */
    public function group(array $attributes, Closure $callback)
    {
        $this->groupAttributes = $attributes;

        call_user_func($callback, $this);

        $this->groupAttributes = [];
    }

    /**
     * Fire potential driver event callbacks.
     */
    protected function fireDriverEvents()
    {
        $driverEvent = $this->getDriver()->hasMatchingEvent();
        if ($driverEvent instanceof DriverEventInterface) {
            Collection::make($this->events)->filter(function ($event) use ($driverEvent) {
                return $driverEvent->getName() === $event['name'];
            })->each(function ($event) use ($driverEvent) {
                call_user_func_array($event['closure'], [$driverEvent->getPayload(), $this]);
            });
        }
    }

    /**
     * @param bool $withReceivedMiddleware
     * @return MatchingMessage[]
     */
    protected function getMatchingMessages($withReceivedMiddleware = true)
    {
        $matchingMessages = [];
        foreach ($this->getMessages() as $message) {
            if ($withReceivedMiddleware) {
                $message = $this->middleware->applyMiddleware('received', $message);
            }

            foreach ($this->listenTo as $command) {
                $messageData = $command->toArray();
                $pattern = $messageData['pattern'];

                if (! $this->isBot() &&
                    $this->matcher->isMessageMatching($message, $this->getConversationAnswer()->getValue(), $pattern,
                        $messageData['middleware'] + $this->middleware->heard()) &&
                    $this->matcher->isDriverValid($this->driver->getName(), $messageData['driver']) &&
                    $this->matcher->isRecipientValid($message->getRecipient(), $messageData['recipient']) &&
                    $this->loadedConversation === false
                ) {
                    $matchingMessages[] = new MatchingMessage($command, $message, $this->matcher->getMatches());
                }
            }
        }

        return $matchingMessages;
    }

    /**
     * Try to match messages with the ones we should
     * listen to.
     */
    public function listen()
    {
        $this->fireDriverEvents();

        if (! $this->isBot()) {
            $this->loadActiveConversation();
        }

        $matchingMessages = $this->getMatchingMessages();
        foreach ($matchingMessages as $matchingMessage) {
            $this->command = $matchingMessage->getCommand();
            $callback = $this->command->getCallback();

            if (! $callback instanceof Closure) {
                $callback = $this->getCallable($callback);
            }

            $this->message = $this->middleware->applyMiddleware('heard', $matchingMessage->getMessage(), $this->command->getMiddleware());
            $parameterNames = $this->compileParameterNames($this->command->getPattern());

            $matches = $matchingMessage->getMatches();
            if (count($parameterNames) === count($matches)) {
                $parameters = array_combine($parameterNames, $matches);
            } else {
                $parameters = $matches;
            }

            $this->matches = $parameters;
            array_unshift($parameters, $this);

            $parameters = $this->addDataParameters($this->message, $parameters);

            call_user_func_array($callback, $parameters);
        }
        if (empty($matchingMessages) && ! $this->isBot() && is_callable($this->fallbackMessage) && $this->loadedConversation === false) {
            $this->message = $this->getMessages()[0];
            call_user_func($this->fallbackMessage, $this);
        }
    }

    /**
     * @param string|Question $message
     * @param string|array $recipient
     * @param DriverInterface|null $driver
     * @param array $additionalParameters
     * @return $this
     */
    public function say($message, $recipient, $driver = null, $additionalParameters = [])
    {
        if (is_null($driver)) {
            $drivers = DriverManager::getConfiguredDrivers($this->config);
        } elseif (is_string($driver)) {
            $drivers = [DriverManager::loadFromName($driver, $this->config)];
        } else {
            $drivers = [$driver];
        }

        foreach ($drivers as $driver) {
            $this->message = new Message('', $recipient, '');
            $this->setDriver($driver);
            $this->reply($message, $additionalParameters);
        }

        return $this;
    }

    /**
     * @param string|Question $question
     * @param array|Closure $next
     * @param array $additionalParameters
     * @return $this
     */
    public function ask($question, $next, $additionalParameters = [])
    {
        $this->reply($question, $additionalParameters);
        $this->storeConversation(new InlineConversation, $next, $question, $additionalParameters);

        return $this;
    }

    /**
     * @return $this
     */
    public function types()
    {
        $this->getDriver()->types($this->message);

        return $this;
    }

    /**
     * @param int $seconds Number of seconds to wait
     * @return $this
     */
    public function typesAndWaits($seconds)
    {
        $this->getDriver()->types($this->message);
        sleep($seconds);

        return $this;
    }

    /**
     * Low-level method to perform driver specific API requests.
     *
     * @param string $endpoint
     * @param array $additionalParameters
     * @return $this
     */
    public function sendRequest($endpoint, $additionalParameters = [])
    {
        $driver = $this->getDriver();
        if (method_exists($driver, 'sendRequest')) {
            return $driver->sendRequest($endpoint, $additionalParameters, $this->message);
        } else {
            throw new \BadMethodCallException('The driver '.$this->getDriver()->getName().' does not support low level requests.');
        }
    }

    /**
     * @param string|Question $message
     * @param array $additionalParameters
     * @return mixed
     */
    public function reply($message, $additionalParameters = [])
    {
        $message = is_string($message) ? OutgoingMessage::create($message) : $message;

        return $this->sendPayload($this->getDriver()->buildServicePayload($message, $this->message, $additionalParameters));
    }

    /**
     * @param $payload
     * @return mixed
     */
    public function sendPayload($payload)
    {
        return $this->middleware->applyMiddleware('sending', $payload, [], function ($payload) {
            return $this->getDriver()->sendPayload($payload);
        });
    }

    /**
     * Return a random message.
     * @param array $messages
     * @return $this
     */
    public function randomReply(array $messages)
    {
        return $this->reply($messages[array_rand($messages)]);
    }

    /**
     * Make an action for an invokable controller.
     *
     * @param string $action
     * @return string
     *
     * @throws \UnexpectedValueException
     */
    protected function makeInvokableAction($action)
    {
        if (! method_exists($action, '__invoke')) {
            throw new UnexpectedValueException(sprintf(
                'Invalid hears action: [%s]', $action
            ));
        }

        return $action.'@__invoke';
    }

    /**
     * @param string $callback
     * @return array
     */
    protected function getCallable($callback)
    {
        if (strpos($callback, '@') === false) {
            $callback = $this->makeInvokableAction($callback);
        }

        list($class, $method) = explode('@', $callback);

        return [new $class($this), $method];
    }

    /**
     * @return array
     */
    public function getMatches()
    {
        return $this->matches;
    }

    /**
     * @return Message
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @param string $name
     * @param mixed $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        if (method_exists($this->getDriver(), $name)) {
            // Add the current message to the passed arguments
            array_push($arguments, $this->getMessage());
            array_push($arguments, $this);

            return call_user_func_array([$this->getDriver(), $name], $arguments);
        }

        throw new \BadMethodCallException('Method ['.$name.'] does not exist.');
    }

    /**
     * Load driver on wakeup.
     */
    public function __wakeup()
    {
        $this->driver = DriverManager::loadFromName($this->driverName, $this->config);
    }

    /**
     * @return array
     */
    public function __sleep()
    {
        $this->driverName = $this->driver->getName();

        return [
            'event',
            'driverName',
            'storage',
            'message',
            'cache',
            'matches',
            'matcher',
            'config',
            'middleware',
        ];
    }
}
