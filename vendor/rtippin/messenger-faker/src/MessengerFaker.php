<?php

namespace RTippin\MessengerFaker;

use Exception;
use Faker\Generator;
use Illuminate\Database\Eloquent\Collection as DBCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use RTippin\Messenger\Actions\BaseMessengerAction;
use RTippin\Messenger\Actions\Messages\StoreSystemMessage;
use RTippin\Messenger\Brokers\NullBroadcastBroker;
use RTippin\Messenger\Contracts\MessengerProvider;
use RTippin\Messenger\Exceptions\FeatureDisabledException;
use RTippin\Messenger\Exceptions\InvalidProviderException;
use RTippin\Messenger\Exceptions\MessengerComposerException;
use RTippin\Messenger\Exceptions\ReactionException;
use RTippin\Messenger\Messenger;
use RTippin\Messenger\Models\Bot;
use RTippin\Messenger\Models\Message;
use RTippin\Messenger\Models\Participant;
use RTippin\Messenger\Models\Thread;
use RTippin\Messenger\Support\MessengerComposer;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

class MessengerFaker
{
    use FakerFiles,
        FakerSystemMessages;

    /**
     * @var Messenger
     */
    private Messenger $messenger;

    /**
     * @var Generator
     */
    private Generator $faker;

    /**
     * @var StoreSystemMessage
     */
    private StoreSystemMessage $storeSystem;

    /**
     * @var Thread|null
     */
    private ?Thread $thread = null;

    /**
     * @var DBCollection|null
     */
    private ?DBCollection $participants = null;

    /**
     * @var DBCollection|null
     */
    private ?DBCollection $messages = null;

    /**
     * @var Collection
     */
    private Collection $usedParticipants;

    /**
     * @var ProgressBar|null
     */
    private ?ProgressBar $bar = null;

    /**
     * @var int
     */
    private int $delay = 0;

    /**
     * @var bool
     */
    private bool $useAdmins = false;

    /**
     * @var bool
     */
    private bool $useBots = false;

    /**
     * @var bool
     */
    private static bool $isTesting = false;

    /**
     * MessengerFaker constructor.
     *
     * @param  Messenger  $messenger
     * @param  Generator  $faker
     * @param  StoreSystemMessage  $storeSystem
     */
    public function __construct(Messenger $messenger,
                                Generator $faker,
                                StoreSystemMessage $storeSystem)
    {
        $this->messenger = $messenger;
        $this->faker = $faker;
        $this->storeSystem = $storeSystem;
        $this->usedParticipants = new Collection;
        $this->messenger
            ->setKnockKnock(true)
            ->setKnockTimeout(0)
            ->setMessageReactions(true)
            ->setSystemMessages(true);
    }

    /**
     * Set testing to true, so we may fake file uploads and remove delays.
     */
    public static function testing(): void
    {
        static::$isTesting = true;

        BaseMessengerAction::disableEvents();
    }

    /**
     * @return Generator
     */
    public function getFakerGenerator(): Generator
    {
        return $this->faker;
    }

    /**
     * @param  string|null  $threadId
     * @param  bool  $useAdmins
     * @param  bool  $useBots
     * @return $this
     *
     * @throws ModelNotFoundException
     */
    public function setThreadWithId(?string $threadId = null,
                                    bool $useAdmins = false,
                                    bool $useBots = false): self
    {
        $this->useAdmins = $useAdmins;
        $this->useBots = $useBots;
        $this->thread = is_null($threadId)
            ? Thread::inRandomOrder()->firstOrFail()
            : Thread::findOrFail($threadId);

        $this->setParticipants();

        return $this;
    }

    /**
     * @param  Thread  $thread
     * @param  bool  $useAdmins
     * @param  bool  $useBots
     * @return $this
     *
     * @throws ModelNotFoundException
     */
    public function setThread(Thread $thread,
                              bool $useAdmins = false,
                              bool $useBots = false): self
    {
        $this->useAdmins = $useAdmins;
        $this->useBots = $useBots;
        $this->thread = $thread;

        $this->setParticipants();

        return $this;
    }

    /**
     * @param  int  $count
     * @return $this
     *
     * @throws Exception
     */
    public function setMessages(int $count = 5): self
    {
        if ($this->thread->messages()->nonSystem()->count() < $count) {
            throw new Exception("{$this->getThreadName()} does not have $count or more messages to choose from.");
        }

        $this->messages = $this->thread
            ->messages()
            ->nonSystem()
            ->latest()
            ->with('owner')
            ->limit($count)
            ->get();

        return $this;
    }

    /**
     * @param  int  $delay
     * @return $this
     */
    public function setDelay(int $delay): self
    {
        if (! static::$isTesting) {
            $this->delay = $delay;
        }

        return $this;
    }

    /**
     * @param  bool  $silence
     * @return $this
     */
    public function setSilent(bool $silence = false): self
    {
        if ($silence) {
            BaseMessengerAction::disableEvents();
            $this->messenger->setBroadcastDriver(NullBroadcastBroker::class);
        }

        return $this;
    }

    /**
     * @param  ProgressBar|null  $bar
     * @return $this
     */
    public function setProgressBar(?ProgressBar $bar): self
    {
        $this->bar = $bar;

        return $this;
    }

    /**
     * @return Thread|null
     */
    public function getThread(): ?Thread
    {
        return $this->thread;
    }

    /**
     * @return DBCollection|null
     */
    public function getParticipants(): ?DBCollection
    {
        return $this->participants;
    }

    /**
     * @return Thread
     */
    public function getThreadName(): string
    {
        return $this->thread->isGroup()
            ? $this->thread->name()
            : "{$this->participants->first()->owner->getProviderName()} and {$this->participants->last()->owner->getProviderName()}";
    }

    /**
     * Send a knock to the given thread.
     *
     * @return $this
     *
     * @throws FeatureDisabledException|InvalidProviderException
     * @throws Throwable
     */
    public function knock(): self
    {
        $this->composer()->from($this->participants->first()->owner)->knock();

        if ($this->thread->isPrivate()) {
            $this->composer()->from($this->participants->last()->owner)->knock();
        }

        return $this;
    }

    /**
     * Mark the given providers as read and send broadcast.
     *
     * @param  Participant|null  $participant
     * @param  Message|null  $message
     * @return $this
     *
     * @throws Throwable
     */
    public function read(Participant $participant = null, ?Message $message = null): self
    {
        $message = $message ?? $this->thread->messages()->latest()->first();

        if (! is_null($message)) {
            if (! is_null($participant)) {
                $this->composer()
                    ->from($participant->owner)
                    ->emitRead($message)
                    ->read($participant);
            } else {
                $this->participants->each(
                    fn (Participant $participant) => $this->composer()
                        ->from($participant->owner)
                        ->emitRead($message)
                        ->read($participant)
                );
            }
        }

        return $this;
    }

    /**
     * Mark the given providers as unread.
     *
     * @return $this
     */
    public function unread(): self
    {
        $this->participants->each(
            fn (Participant $participant) => $participant->update(['last_read' => null])
        );

        return $this;
    }

    /**
     * Make the given providers send typing broadcast.
     *
     * @param  MessengerProvider|null  $provider
     * @return $this
     *
     * @throws Throwable
     */
    public function typing(MessengerProvider $provider = null): self
    {
        if (! is_null($provider)) {
            $this->composer()->from($provider)->emitTyping();
        } else {
            $this->participants->each(
                fn (Participant $participant) => $this->composer()
                    ->from($participant->owner)
                    ->emitTyping()
            );
        }

        return $this;
    }

    /**
     * Send messages using the given providers and show typing and mark read.
     *
     * @param  bool  $isFinal
     * @return $this
     *
     * @throws Throwable
     */
    public function message(bool $isFinal = false): self
    {
        $this->startMessage();

        if (rand(0, 100) > 80) {
            $message = '';
            for ($x = 0; $x < rand(1, 10); $x++) {
                $message .= $this->faker->emoji;
            }
        } else {
            $message = $this->faker->realText(rand(10, 200), rand(1, 4));
        }

        $this->composer()->message($message);

        $this->endMessage($isFinal);

        return $this;
    }

    /**
     * Send image messages using the given providers and show typing and mark read.
     *
     * @param  bool  $isFinal
     * @param  bool  $local
     * @param  string|null  $url
     * @return $this
     *
     * @throws Throwable
     */
    public function image(bool $isFinal = false,
                          bool $local = false,
                          ?string $url = null): self
    {
        $this->startMessage();

        $image = $this->getImage($local, $url);

        $this->composer()->image($image[0]);

        $this->endMessage($isFinal);

        if (! $local) {
            $this->unlinkFile($image[1]);
        }

        return $this;
    }

    /**
     * Send document messages using the given providers and show typing and mark read.
     *
     * @param  bool  $isFinal
     * @param  string|null  $url
     * @return $this
     *
     * @throws Throwable
     */
    public function document(bool $isFinal = false, ?string $url = null): self
    {
        $this->startMessage();

        $document = $this->getDocument($url);

        $this->composer()->document($document[0]);

        $this->endMessage($isFinal);

        if (! is_null($url)) {
            $this->unlinkFile($document[1]);
        }

        return $this;
    }

    /**
     * Send audio messages using the given providers and show typing and mark read.
     *
     * @param  bool  $isFinal
     * @param  string|null  $url
     * @return $this
     *
     * @throws Throwable
     */
    public function audio(bool $isFinal = false, ?string $url = null): self
    {
        $this->startMessage();

        $audio = $this->getAudio($url);

        $this->composer()->audio($audio[0]);

        $this->endMessage($isFinal);

        if (! is_null($url)) {
            $this->unlinkFile($audio[1]);
        }

        return $this;
    }

    /**
     * Send video messages using the given providers and show typing and mark read.
     *
     * @param  bool  $isFinal
     * @param  string|null  $url
     * @return $this
     *
     * @throws Throwable
     */
    public function video(bool $isFinal = false, ?string $url = null): self
    {
        $this->startMessage();

        $video = $this->getVideo($url);

        $this->composer()->video($video[0]);

        $this->endMessage($isFinal);

        if (! is_null($url)) {
            $this->unlinkFile($video[1]);
        }

        return $this;
    }

    /**
     * @param  int|null  $type
     * @param  bool  $isFinal
     * @return $this
     *
     * @throws Throwable
     */
    public function system(?int $type = null, bool $isFinal = false): self
    {
        $this->storeSystem->execute(...$this->generateSystemMessage($type));

        $this->sleepAndAdvance($isFinal);

        return $this;
    }

    /**
     * @return $this
     *
     * @throws FeatureDisabledException|Throwable
     * @throws Throwable
     */
    public function reaction(bool $isFinal = false): self
    {
        try {
            $this->composer()
                ->from($this->participants->random()->owner)
                ->reaction($this->messages->random(), $this->faker->emoji);
        } catch (ReactionException $e) {
            // continue as it may pick duplicate random emoji
        }

        $this->sleepAndAdvance($isFinal);

        return $this;
    }

    /**
     * Messages started.
     *
     * @throws Throwable
     */
    private function startMessage(): void
    {
        /** @var Participant $participant */
        $participant = $this->participants->random();
        $this->usedParticipants->push($participant);
        $this->composer()->from($participant->owner)->emitTyping();
    }

    /**
     * Messages ended.
     *
     * @param  bool  $isFinal
     *
     * @throws Throwable
     */
    private function endMessage(bool $isFinal): void
    {
        if (! $isFinal) {
            $this->sleepAndAdvance($isFinal);

            return;
        }

        if (! $this->useBots) {
            $message = $this->thread->messages()->latest()->first();

            $this->usedParticipants
                ->unique('id')
                ->each(fn (Participant $participant) => $this->read($participant, $message));
        }

        $this->usedParticipants = new Collection;
    }

    /**
     * @param  bool  $isFinal
     */
    private function sleepAndAdvance(bool $isFinal): void
    {
        if (! is_null($this->bar)) {
            $this->bar->advance();
        }

        if (! $isFinal) {
            sleep($this->delay);
        }
    }

    /**
     * @return MessengerComposer
     *
     * @throws MessengerComposerException
     */
    private function composer(): MessengerComposer
    {
        return app(MessengerComposer::class)->to($this->thread);
    }

    /**
     * @return void
     */
    private function setParticipants(): void
    {
        if ($this->thread->isGroup()) {
            if ($this->useAdmins) {
                $this->participants = $this->thread
                    ->participants()
                    ->admins()
                    ->with('owner')
                    ->get();

                return;
            }

            if ($this->useBots) {
                $this->participants = $this->thread
                    ->bots()
                    ->get()
                    ->map(
                        fn (Bot $bot) => (new Participant)->setRelation('owner', $bot)
                    );

                return;
            }
        }

        $this->participants = $this->thread
            ->participants()
            ->with('owner')
            ->get();
    }
}
