<?php

namespace RTippin\MessengerBots\Bots;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RTippin\Messenger\MessengerBots;
use RTippin\Messenger\Support\BotActionHandler;
use Throwable;

class QuotableBot extends BotActionHandler
{
    /**
     * Endpoint we gather data from.
     */
    const API_ENDPOINT = 'https://quote-garden.herokuapp.com/api/v3/quotes/random';

    /**
     * The bots settings.
     *
     * @return array
     */
    public static function getSettings(): array
    {
        return [
            'alias' => 'quotable',
            'description' => 'Get a random quote.',
            'name' => 'Quotable Quotes',
            'unique' => true,
            'triggers' => ['!quote', '!inspire', '!quotable'],
            'match' => MessengerBots::MATCH_EXACT_CASELESS,
        ];
    }

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        $quote = $this->getQuote();

        if ($quote->failed()) {
            $this->releaseCooldown();

            return;
        }

        $this->sendQuoteMessage($quote->json('data')[0]);
    }

    /**
     * @param  array  $quote
     *
     * @throws Throwable
     */
    private function sendQuoteMessage(array $quote): void
    {
        $this->composer()->emitTyping()->message(":speech_left: \"{$quote['quoteText']}\" - {$quote['quoteAuthor']}");
    }

    /**
     * @return Response
     */
    private function getQuote(): Response
    {
        return Http::acceptJson()->timeout(5)->get(self::API_ENDPOINT);
    }
}
