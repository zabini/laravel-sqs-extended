<?php

declare(strict_types=1);

namespace DefectiveCode\LaravelSqsExtended;

use DateInterval;
use Aws\Sqs\SqsClient;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Queue\SqsQueue;
use Illuminate\Contracts\Queue\Job;

class SqsDiskQueue extends SqsQueue
{
    use ResolvesPointers;

    /**
     * The max length of a SQS message before it must be stored as a pointer.
     *
     * @var int
     */
    public const MAX_SQS_LENGTH = 250000;

    /**
     * The disk options to save large payloads.
     */
    protected array $diskOptions;

    /**
     * Create a new Amazon SQS queue instance.
     *
     * @param  string  $default
     * @param  array  $diskOptions
     * @param  string  $prefix
     * @param  string  $suffix
     * @param  bool  $dispatchAfterCommit
     * @return void
     */
    public function __construct(
        SqsClient $sqs,
        $default,
        $diskOptions,
        $prefix = '',
        $suffix = '',
        $dispatchAfterCommit = false,
    ) {
        $this->diskOptions = $diskOptions;

        parent::__construct($sqs, $default, $prefix, $suffix, $dispatchAfterCommit);
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string  $payload
     * @param  string|null  $queue
     * @param  mixed  $delay
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [], $delay = 0)
    {
        $message = [
            'QueueUrl' => $this->getQueue($queue),
            'MessageBody' => $payload,
        ];

        if (strlen($payload) >= self::MAX_SQS_LENGTH || Arr::get($this->diskOptions, 'always_store')) {
            $uuid = json_decode($payload)->uuid;
            $filepath = Arr::get($this->diskOptions, 'prefix', '') . "/{$uuid}.json";
            $this->resolveDisk()->put($filepath, $payload);

            retry(
                Arr::get($this->diskOptions, 'retry.times', 10),
                function () use ($filepath) {

                    if (! $this->resolveDisk()->exists($filepath)) {
                        throw new \Exception("S3 propagation delay: arquivo ainda não disponível após upload: {$filepath}");
                    }
                },
                Arr::get($this->diskOptions, 'retry.sleep_milliseconds', 2000)
            );

            $message['MessageBody'] = json_encode(['pointer' => $filepath]);
        }

        if ($delay) {
            $message['DelaySeconds'] = $this->secondsUntil($delay);
        }

        return $this->sqs->sendMessage($message)->get('MessageId');
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  DateTimeInterface|DateInterval|int  $delay
     * @param  string  $job
     * @param  mixed  $data
     * @param  string|null  $queue
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue ?: $this->default, $data),
            $queue,
            $delay,
            function ($payload, $queue) use ($delay) {
                return $this->pushRaw($payload, $queue, [], $delay);
            }
        );
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param  string|null  $queue
     * @return Job|null
     */
    public function pop($queue = null)
    {
        $response = $this->sqs->receiveMessage([
            'QueueUrl' => $queue = $this->getQueue($queue),
            'AttributeNames' => ['ApproximateReceiveCount'],
        ]);

        if (! is_null($response['Messages']) && count($response['Messages']) > 0) {
            return new SqsDiskJob(
                $this->container,
                $this->sqs,
                $response['Messages'][0],
                $this->connectionName,
                $queue,
                $this->diskOptions
            );
        }
    }

    /**
     * Delete all the jobs from the queue.
     *
     * @param  string  $queue
     * @return int
     */
    public function clear($queue)
    {
        return tap(parent::clear($queue), function (): void {
            if (Arr::get($this->diskOptions, 'cleanup') && Arr::get($this->diskOptions, 'prefix')) {
                $this->resolveDisk()->deleteDirectory(Arr::get($this->diskOptions, 'prefix'));
            }
        });
    }
}
