<?php

namespace ApiGoat\Ai\Chat;

/** One answer from ChatAssistant::ask(), shaped for the JSON the panel reads. */
final class ChatAnswer
{
    public string $answer;
    /** @var array<int,array{id:string,label:string,href?:string}> only the sources the answer cites */
    public array $sources;
    /** @var array{input_tokens:int,output_tokens:int} */
    public array $usage;
    public int $latencyMs;
    public string $model;

    /**
     * @param array<int,array{id:string,label:string,href?:string}> $sources
     * @param array{input_tokens:int,output_tokens:int} $usage
     */
    public function __construct(string $answer, array $sources, array $usage, int $latencyMs, string $model)
    {
        $this->answer = $answer;
        $this->sources = $sources;
        $this->usage = $usage;
        $this->latencyMs = $latencyMs;
        $this->model = $model;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'answer'     => $this->answer,
            'sources'    => $this->sources,
            'usage'      => $this->usage,
            'latency_ms' => $this->latencyMs,
            'model'      => $this->model,
        ];
    }
}
