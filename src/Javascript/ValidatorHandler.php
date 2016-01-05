<?php

namespace Proengsoft\JsValidation\Javascript;

use Illuminate\Validation\Validator;
use Proengsoft\JsValidation\Support\DelegatedValidator;
use Proengsoft\JsValidation\Support\UseDelegatedValidatorTrait;

class ValidatorHandler
{
    use UseDelegatedValidatorTrait;

    /**
     * Rule used to disable validations.
     *
     * @const string
     */
    const JSVALIDATION_DISABLE = 'NoJsValidation';

    /**
     * @var RuleParser
     */
    protected $rules;
    /**
     * @var MessageParser
     */
    protected $messages;

    protected $conditional = [];

    /**
     * Create a new JsValidation instance.
     *
     * @param RuleParser $rules
     * @param MessageParser $messages
     */
    public function __construct(RuleParser $rules, MessageParser $messages)
    {
        $this->rules = $rules;
        $this->messages = $messages;
        $this->validator = $rules->getDelegatedValidator();
    }

    /**
     * Sets delegated Validator instance.
     *
     * @param \Proengsoft\JsValidation\Support\DelegatedValidator $validator
     */
    public function setDelegatedValidator(DelegatedValidator $validator)
    {
        $this->validator = $validator;
        $this->rules->setDelegatedValidator($validator);
        $this->messages->setDelegatedValidator($validator);
    }

    protected function generateJavascriptValidations($includeRemote = true)
    {
        $jsValidations = array();

        foreach ($this->validator->getRules() as $attribute => $rules) {
            if (! $this->jsValidationEnabled($attribute)) {
                continue;
            }

            $newRules = $this->jsConvertRules($attribute, $rules, $includeRemote);
            $jsValidations = array_merge($jsValidations, $newRules);
        }

        return $jsValidations;
    }

    /**
     * Make Laravel Validations compatible with JQuery Validation Plugin.
     *
     * @param $attribute
     * @param $rules
     * @param $includeRemote
     *
     * @return array
     */
    protected function jsConvertRules($attribute, $rules, $includeRemote)
    {
        $jsRules = [];
        foreach ($rules as $rawRule) {
            $forceRemote = $this->isConditionalRule($attribute, $rawRule);
            list($rule, $parameters) = $this->validator->parseRule($rawRule);
            list($jsAttribute, $jsRule, $jsParams) = $this->rules->getRule($attribute, $rule, $parameters, $forceRemote);
            if ($this->isValidatable($jsRule, $includeRemote)) {
                $jsRules[$jsAttribute][$jsRule][] = array($rule, $jsParams,
                    $this->messages->getMessage($attribute, $rule, $parameters),
                    $this->validator->isImplicit($rule),
                );
            }
        }

        return $jsRules;
    }

    /**
     * Check if rule should be validated with javascript.
     *
     * @param $jsRule
     * @param $includeRemote
     * @return bool
     */
    protected function isValidatable($jsRule, $includeRemote)
    {
        return $jsRule && ($includeRemote || $jsRule !== RuleParser::REMOTE_RULE);
    }

    /**
     * Check if JS Validation is disabled for attribute.
     *
     * @param $attribute
     *
     * @return bool
     */
    public function jsValidationEnabled($attribute)
    {
        return ! $this->validator->hasRule($attribute, self::JSVALIDATION_DISABLE);
    }

    /**
     * Returns view data to render javascript.
     *
     * @param bool $remote
     * @return array
     */
    public function validationData($remote = true)
    {
        $jsMessages = array();
        $jsValidations = $this->generateJavascriptValidations($remote);

        return [
            'rules' => $jsValidations,
            'messages' => $jsMessages,
        ];
    }

    /**
     * Validate Conditional Validations using Ajax in specified fields.
     *
     * @param  string|array  $attribute
     * @param  string|array  $rules
     */
    public function sometimes($attribute, $rules = [])
    {
        $this->validator->sometimes($attribute, $rules, function () {
            return true;
        });
        foreach ((array) $attribute as $key) {
            $current = isset($this->conditional[$key]) ? $this->conditional[$key] : [];
            $merge = head($this->validator->explodeRules([$rules]));
            $this->conditional[$key] = array_merge($current, $merge);
        }
    }

    /**
     * Determine if rule is passed with sometimes.
     *
     * @param $attribute
     * @param $rule
     * @return bool
     */
    protected function isConditionalRule($attribute, $rule)
    {
        return isset($this->conditional[$attribute]) &&
            in_array($rule, $this->conditional[$attribute]);
    }
}
