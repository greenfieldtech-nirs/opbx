<?php

declare(strict_types=1);

namespace App\Services\IvrMenu;

use App\Services\IvrMenu\Validators\IvrMenuValidatorInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * Pipeline for running multiple IVR menu validation strategies.
 */
class IvrMenuValidationPipeline
{
    /** @var Collection<int, IvrMenuValidatorInterface> */
    private Collection $validators;

    public function __construct()
    {
        $this->validators = collect();
    }

    /**
     * Add a validator to the pipeline.
     */
    public function addValidator(IvrMenuValidatorInterface $validator): self
    {
        $this->validators->push($validator);
        return $this;
    }

    /**
     * Run all validators in the pipeline.
     */
    public function validate(Validator $validator, array $data, ?int $excludeMenuId = null): void
    {
        foreach ($this->validators as $pipelineValidator) {
            $pipelineValidator->validate($validator, $data, $excludeMenuId);
        }
    }

    /**
     * Create a default pipeline with standard validators.
     */
    public static function createDefault(): self
    {
        $pipeline = new self();
        $pipeline->addValidator(new \App\Services\IvrMenu\Validators\IvrMenuDestinationValidator());
        $pipeline->addValidator(new \App\Services\IvrMenu\Validators\IvrMenuPriorityValidator());
        $pipeline->addValidator(new \App\Services\IvrMenu\Validators\IvrMenuAudioValidator());

        return $pipeline;
    }
}