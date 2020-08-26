<?php
namespace verbb\formie\integrations\crm;

use verbb\formie\Formie;
use verbb\formie\base\Crm;
use verbb\formie\base\Integration;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\errors\IntegrationException;
use verbb\formie\events\SendIntegrationPayloadEvent;
use verbb\formie\models\IntegrationField;
use verbb\formie\models\EmailMarketingList;

use Craft;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\web\View;

class Salesforce extends Crm
{
    // Properties
    // =========================================================================

    public $clientId;
    public $clientSecret;
    public $apiDomain;
    public $matchLead;
    public $useSandbox = false;
    public $mapToContact = false;
    public $mapToLead = false;
    public $mapToOpportunity = false;
    public $mapToAccount = false;
    public $contactFieldMapping;
    public $leadFieldMapping;
    public $opportunityFieldMapping;
    public $accountFieldMapping;

    private $users = [];


    // OAuth Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function supportsOauthConnection(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getAuthorizeUrl(): string
    {
        $prefix = $this->useSandbox ? 'test' : 'login';

        return "https://{$prefix}.salesforce.com/services/oauth2/authorize";
    }

    /**
     * @inheritDoc
     */
    public function getAccessTokenUrl(): string
    {
        $prefix = $this->useSandbox ? 'test' : 'login';

        return "https://{$prefix}.salesforce.com/services/oauth2/token";
    }

    /**
     * @inheritDoc
     */
    public function getClientId(): string
    {
        return $this->clientId;
    }

    /**
     * @inheritDoc
     */
    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    /**
     * @inheritDoc
     */
    public function afterFetchAccessToken($token)
    {
        // Save these properties for later...
        $this->apiDomain = $token->getValues()['instance_url'] ?? '';
    }


    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function displayName(): string
    {
        return Craft::t('formie', 'Salesforce');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return Craft::t('formie', 'Manage your Salesforce customers by providing important information on their conversion on your site.');
    }

    /**
     * @inheritDoc
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['clientId', 'clientSecret'], 'required'];

        $contact = $this->getFormSettings()['contact'] ?? [];
        $lead = $this->getFormSettings()['lead'] ?? [];
        $opportunity = $this->getFormSettings()['opportunity'] ?? [];
        $account = $this->getFormSettings()['account'] ?? [];

        // Validate the following when saving form settings
        $rules[] = [['contactFieldMapping'], 'validateFieldMapping', 'params' => $contact, 'when' => function($model) {
            return $model->enabled && $model->mapToContact;
        }, 'on' => [Integration::SCENARIO_FORM]];

        $rules[] = [['leadFieldMapping'], 'validateFieldMapping', 'params' => $lead, 'when' => function($model) {
            return $model->enabled && $model->mapToLead;
        }, 'on' => [Integration::SCENARIO_FORM]];

        $rules[] = [['opportunityFieldMapping'], 'validateFieldMapping', 'params' => $opportunity, 'when' => function($model) {
            return $model->enabled && $model->mapToOpportunity;
        }, 'on' => [Integration::SCENARIO_FORM]];

        $rules[] = [['accountFieldMapping'], 'validateFieldMapping', 'params' => $account, 'when' => function($model) {
            return $model->enabled && $model->mapToAccount;
        }, 'on' => [Integration::SCENARIO_FORM]];

        return $rules;
    }

    /**
     * @inheritDoc
     */
    public function fetchFormSettings()
    {
        $settings = [];

        try {
            // Get Users - saved for use with owner ID fields
            $response = $this->request('GET', 'query', [
                'query' => ['q' => 'SELECT ID,Name FROM User LIMIT 20'],
            ]);

            $users = $response['records'] ?? [];

            foreach ($users as $user) {
                $this->users[] = [
                    'label' => $user['Name'],
                    'value' => $user['Id'],
                ];
            }

            // Get Contact fields
            $response = $this->request('GET', 'sobjects/Contact/describe');
            $fields = $response['fields'] ?? [];
            $contactFields = $this->_getCustomFields($fields);

            // Get Lead fields
            $response = $this->request('GET', 'sobjects/Lead/describe');
            $fields = $response['fields'] ?? [];
            $leadFields = $this->_getCustomFields($fields);

            // Get Opportunity fields
            $response = $this->request('GET', 'sobjects/Opportunity/describe');
            $fields = $response['fields'] ?? [];
            $opportunityFields = $this->_getCustomFields($fields);

            // Get Account fields
            $response = $this->request('GET', 'sobjects/Account/describe');
            $fields = $response['fields'] ?? [];
            $accountFields = $this->_getCustomFields($fields);

            $settings = [
                'contact' => $contactFields,
                'lead' => $leadFields,
                'opportunity' => $opportunityFields,
                'account' => $accountFields,
            ];
        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]), true);
        }

        return $settings;
    }

    /**
     * @inheritDoc
     */
    public function sendPayload(Submission $submission): bool
    {
        try {
            $contactValues = $this->getFieldMappingValues($submission, $this->contactFieldMapping);
            $leadValues = $this->getFieldMappingValues($submission, $this->leadFieldMapping);
            $opportunityValues = $this->getFieldMappingValues($submission, $this->opportunityFieldMapping);
            $accountValues = $this->getFieldMappingValues($submission, $this->accountFieldMapping);

            $accountId = null;
            $accountOwnerId = null;

            if ($this->mapToAccount) {
                $accountPayload = $this->_prepPayload($accountValues);

                $response = $this->deliverPayload($submission, 'sobjects/Account', $accountPayload);

                if ($response === false) {
                    return false;
                }

                $accountId = $response['id'] ?? '';

                if (!$accountId) {
                    Integration::error($this, Craft::t('formie', 'Missing return “accountId” {response}', [
                        'response' => Json::encode($response),
                    ]), true);

                    return false;
                }
            }

            $contactId = null;
            $contactOwnerId = null;

            if ($this->mapToContact) {
                $contactPayload = $this->_prepPayload($contactValues);

                // Doesn't support upsert, so try to find the record first
                $response = $this->request('GET', 'query', [
                    'query' => ['q' => "SELECT ID,OwnerId FROM Contact WHERE Email = '{$contactPayload['Email']}' LIMIT 1"],
                ]);

                $contactId = $response['records'][0]['Id'] ?? '';
                $contactOwnerId = $response['records'][0]['OwnerId'] ?? '';

                if ($accountId) {
                    $contactPayload['AccountId'] = $accountId;
                }

                // Update the record
                if ($contactId) {
                    $response = $this->deliverPayload($submission, "sobjects/Contact/$contactId", $contactPayload, 'PATCH');

                    if ($response === false) {
                        return false;
                    }
                } else {
                    // Create the new record
                    $response = $this->deliverPayload($submission, 'sobjects/Contact', $contactPayload);

                    if ($response === false) {
                        return false;
                    }

                    $contactId = $response['id'] ?? '';

                    if (!$contactId) {
                        Integration::error($this, Craft::t('formie', 'Missing return “contactId” {response}', [
                            'response' => Json::encode($response),
                        ]), true);

                        return false;
                    }

                    // Have to re-fetch the contact to get more values
                    $response = $this->request('GET', 'query', [
                        'query' => ['q' => "SELECT ID,OwnerId FROM Contact WHERE Id = '{$contactId}' LIMIT 1"],
                    ]);

                    $contactId = $response['records'][0]['Id'] ?? '';
                    $contactOwnerId = $response['records'][0]['OwnerId'] ?? '';
                }
            }

            if ($this->mapToLead) {
                $leadPayload = $this->_prepPayload($leadValues);

                // Add contact ID as the owner, if not set
                if ($contactOwnerId && !isset($leadPayload['OwnerId'])) {
                    $leadPayload['OwnerId'] = $contactOwnerId;
                }

                if (isset($leadPayload['IsUnreadByOwner'])) {
                    $leadPayload['IsUnreadByOwner'] = (bool)$leadPayload['IsUnreadByOwner'];
                } else {
                    $leadPayload['IsUnreadByOwner'] = true;
                }

                try {
                    $response = $this->deliverPayload($submission, 'sobjects/Lead', $leadPayload);

                    if ($response === false) {
                        return false;
                    }

                    $leadId = $response['id'] ?? '';

                    if (!$leadId) {
                        Integration::error($this, Craft::t('formie', 'Missing return “leadId” {response}', [
                            'response' => Json::encode($response),
                        ]), true);

                        return false;
                    }
                } catch (\Throwable $e) {
                    // Ignore duplicate warnings and continue, but still log
                    Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]));
                }
            }

            if ($this->mapToOpportunity) {
                $opportunityPayload = $this->_prepPayload($opportunityValues);

                // Add contact ID as the owner, if not set
                if ($contactOwnerId && !isset($opportunityPayload['OwnerId'])) {
                    $opportunityPayload['OwnerId'] = $contactOwnerId;
                }

                if (isset($opportunityPayload['IsPrivate'])) {
                    $opportunityPayload['IsPrivate'] = (bool)$opportunityPayload['IsPrivate'];
                } else {
                    $opportunityPayload['IsPrivate'] = false;
                }

                if (isset($opportunityPayload['CloseDate'])) {
                    $opportunityPayload['CloseDate'] = DateTimeHelper::toDateTime($opportunityPayload['CloseDate'])->format('Y-m-d');
                } else {
                    $opportunityPayload['CloseDate'] = (new DateTime())->format('Y-m-d');
                }

                if ($accountId) {
                    $opportunityPayload['AccountId'] = $accountId;
                }

                $response = $this->deliverPayload($submission, 'sobjects/Opportunity', $opportunityPayload);

                if ($response === false) {
                    return false;
                }

                $opportunityId = $response['id'] ?? '';

                if (!$opportunityId) {
                    Integration::error($this, Craft::t('formie', 'Missing return “opportunityId” {response}', [
                        'response' => Json::encode($response),
                    ]), true);

                    return false;
                }
            }
        } catch (\Throwable $e) {
            Integration::error($this, Craft::t('formie', 'API error: “{message}” {file}:{line}', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]), true);

            return false;
        }

        return true;
    }


    // Protected Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    protected function getClient()
    {
        if ($this->_client) {
            return $this->_client;
        }

        $token = $this->getToken();

        $this->_client = Craft::createGuzzleClient([
            'base_uri' => "{$this->apiDomain}/services/data/v49.0/",
            'headers' => [
                'Authorization' => 'Bearer ' . $token->accessToken ?? '',
                'Content-Type' => 'application/json',
            ],
        ]);

        // Always provide an authenticated client - so check first.
        // We can't always rely on the EOL of the token.
        try {
            $response = $this->request('GET', '/');
        } catch (\Throwable $e) {
            if ($e->getCode() === 401) {
                // Force-refresh the token
                Formie::$plugin->getTokens()->refreshToken($token, true);

                // Then try again, with the new access token
                $this->_client = Craft::createGuzzleClient([
                    'base_uri' => "{$this->apiDomain}/services/data/v49.0/",
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token->accessToken ?? '',
                        'Content-Type' => 'application/json',
                    ],
                ]);
            }
        }

        return $this->_client;
    }


    // Private Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    private function _getCustomFields($fields, $excludeNames = [])
    {
        $customFields = [];

        $supportedFields = [
            'string',
            'textarea',
            'email',
            'url',
            'address',
            'picklist',
            'phone',
            'reference',
            'boolean',
            'multipicklist',
            'int',
            'number',
            'currency',
            'double',
            'date',
            'datetime',
        ];

        foreach ($fields as $key => $field) {
            if (!$field['updateable']) {
                continue;
            }

            // Only allow supported types
            if (!in_array($field['type'], $supportedFields)) {
                 continue;
            }

            // Exclude any names
            if (in_array($field['name'], $excludeNames)) {
                 continue;
            }

            $options = [];

            // Populate some fields
            if ($field['name'] === 'OwnerId') {
                if ($this->users) {
                    $options = array_merge($options, $this->users);
                }
            }

            // Any picklist values should be kept
            $pickListValues = $field['picklistValues'] ?? [];

            foreach ($pickListValues as $key => $pickListValue) {
                $options[] = [
                    'label' => $pickListValue['label'],
                    'value' => $pickListValue['value'],
                ];
            }

            if ($options) {
                $options = [
                    'label' => $field['label'],
                    'options' => $options,
                ];
            }

            $customFields[] = new IntegrationField([
                'handle' => $field['name'],
                'name' => $field['label'],
                'type' => $field['type'],
                'required' => !$field['nillable'],
                'options' => $options,
            ]);
        }

        return $customFields;
    }

    /**
     * @inheritDoc
     */
    private function _prepPayload($fields)
    {
        $payload = $fields;

        // Check to see if the ownerId is an email, special handling for that
        $ownerId = $payload['OwnerId'] ?? '';

        if ($ownerId && strstr($ownerId, '@')) {
            $ownerId = ArrayHelper::remove($payload, 'OwnerId');

            $payload['Owner'] = [
                'attributes' => ['type' => 'User'],
                'Email' => $ownerId,
            ];
        }

        return $payload;
    }
}