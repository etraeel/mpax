<?php

namespace RTippin\MessengerBots\Bots;

use Illuminate\Support\Collection;
use RTippin\Messenger\Support\BotActionHandler;
use Throwable;

class JokeBot extends BotActionHandler
{
    /**
     * Location of our jokes!
     */
    const JOKES_FILE = __DIR__.'/../../assets/jokes.json';

    /**
     * The bots settings.
     *
     * @return array
     */
    public static function getSettings(): array
    {
        return [
            'alias' => 'random_joke',
            'description' => 'Get a random joke. Has a setup and a punchline.',
            'name' => 'Jokester',
            'unique' => true,
        ];
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $joke = $this->getJoke();

        $this->composer()->emitTyping()->message($joke['setup']);

        if (! static::isTesting()) {
            sleep(6);
        }

        $this->composer()->emitTyping()->message($joke['punchline']);
    }

    /**
     * @return array
     */
    private function getJoke(): array
    {
        return Collection::make(
            json_decode(
                file_get_contents(self::JOKES_FILE), true
            )
        )->random();
    }
}
