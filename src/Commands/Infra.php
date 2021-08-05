<?php

namespace LaraSurf\LaraSurf\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LaraSurf\LaraSurf\Commands\Traits\HasEnvironmentArgument;
use LaraSurf\LaraSurf\Commands\Traits\HasSubCommand;
use LaraSurf\LaraSurf\Commands\Traits\HasValidEnvironments;
use LaraSurf\LaraSurf\Commands\Traits\InteractsWithAws;
use LaraSurf\LaraSurf\Commands\Traits\InteractsWithLaraSurfConfig;

class Infra extends Command
{
    use InteractsWithLaraSurfConfig;
    use InteractsWithAws;
    use HasValidEnvironments;
    use HasEnvironmentArgument;
    use HasSubCommand;

    const COMMAND_CREATE = 'create';
    const COMMAND_DESTROY = 'destroy';
    const COMMAND_ISSUE_CERTIFICATE = 'issue-certificate';
    const COMMAND_CHECK_CERTIFICATE = 'check-certificate';
    const COMMAND_EMAIL_INBOX = 'email-inbox';
    const COMMAND_EMAIL_OUTBOX = 'email-outbox';

    protected $signature = 'larasurf:infra {subcommand} {environment}';

    protected $description = 'Manipulate the infrastructure for an upstream environment';

    protected $commands = [
        self::COMMAND_CREATE => 'handleCreate',
        self::COMMAND_DESTROY => 'handleDestroy',
        self::COMMAND_ISSUE_CERTIFICATE => 'handleIssueCertificate',
        self::COMMAND_CHECK_CERTIFICATE => 'handleCheckCertificate',
        self::COMMAND_EMAIL_INBOX => 'handleEmailInbox',
        self::COMMAND_EMAIL_OUTBOX => 'handleEmailOutbox',
    ];

    public function handle()
    {
        if (!$this->validateEnvironmentArgument()) {
            return 1;
        }

        if (!$this->validateSubCommandArgument()) {
            return 1;
        }

        return $this->runSubCommand();
    }

    protected function handleCreate()
    {
        $config = $this->getValidLarasurfConfig();

        if (!$config) {
            return 1;
        }

        $environment = $this->argument('environment');

        if (!$this->validateEnvironmentExistsInConfig($config, $environment)) {
            return 1;
        }

        $success = $this->ensureHostedZoneIdInConfig($config, $environment);

        if (!$success) {
            return 1;
        }

        $success = $this->createStack($config, $environment);

        if (!$success) {
            return 1;
        }

        $config['cloud-environments'][$environment]['stack-deployed'] = true;

        $success = $this->writeLaraSurfConfig($config);

        if (!$success) {
            return 1;
        }

        $success = $this->afterCreateStackUpdateParameters($config, $environment);

        if (!$success) {
            return 1;
        }

        return 0;
    }

    protected function handleDestroy()
    {
        $config = $this->getValidLarasurfConfig();

        if (!$config) {
            return 1;
        }

        $environment = $this->argument('environment');

        if (!$this->validateEnvironmentExistsInConfig($config, $environment)) {
            return 1;
        }

        $client = $this->getCloudFormationClient($config, $environment);

        if (!$client) {
            return 1;
        }

        $stack_name = $this->getCloudFormationStackName($config, $environment);

        if (!$stack_name) {
            return 1;
        }

        if ($this->confirm("Are you sure you want to destroy the '$environment' environment?")) {
            $client->deleteStack([
                'StackName' => $stack_name,
            ]);

            $this->info('Stack deletion initiated');
            $this->line("See https://console.aws.amazon.com/cloudformation/home?region={$config['cloud-environments'][$environment]['aws-region']} for stack deletion status");
        }

        $config['cloud-environments'][$environment]['stack-deployed'] = false;

        $success = $this->writeLaraSurfConfig($config);

        if (!$success) {
            return 1;
        }

        return 0;
    }

    protected function handleIssueCertificate()
    {
        $config = $this->getValidLarasurfConfig();

        if (!$config) {
            return 1;
        }

        $environment = $this->argument('environment');

        if (!$this->validateEnvironmentExistsInConfig($config, $environment)) {
            return 1;
        }

        if ($config['cloud-environments'][$environment]['aws-certificate-arn'] &&
            !$this->confirm('AWS Certificate ARN exists in larasurf.json. Issue a new certificate and overwrite?', false)
        ) {
            return 0;
        }

        $success = $this->ensureHostedZoneIdInConfig($config, $environment);

        if (!$success) {
            return 1;
        }

        $client = $this->getAcmClient($config, $environment);

        if (!$client) {
            return 1;
        }

        $this->info("Requesting new certificate for domain '{$config['cloud-environments'][$environment]['domain']}'");

        $result = $client->requestCertificate([
            'DomainName' => $config['cloud-environments'][$environment]['domain'],
            'Tags' => [
                [
                    'Key' => 'Project',
                    'Value' => $config['project-name'],
                ],
                [
                    'Key' => 'Environment',
                    'Value' => $environment,
                ],
            ],
            'ValidationMethod' => 'DNS',
        ]);

        $this->info('New certificate requested successfully');

        $this->line("See https://console.aws.amazon.com/acm/home?region=$environment to create DNS records for verification");

        $arn = $result['CertificateArn'];

        $config['cloud-environments'][$environment]['aws-certificate-arn'] = $arn;

        if (!$this->writeLaraSurfConfig($config)) {
            return 1;
        }

        return 0;
    }

    protected function handleCheckCertificate()
    {
        $config = $this->getValidLarasurfConfig();

        if (!$config) {
            return 1;
        }

        $environment = $this->argument('environment');

        if (!$this->validateEnvironmentExistsInConfig($config, $environment)) {
            return 1;
        }

        if (empty($config['cloud-environments'][$environment]['aws-certificate-arn'])) {
            $this->error('AWS Certificate ARN not set in larasurf.json');

            return 1;
        }

        $client = $this->getAcmClient($config, $environment);

        if (!$client) {
            return 1;
        }

        $result = $client->describeCertificate([
            'CertificateArn' => $config['cloud-environments'][$environment]['aws-certificate-arn'],
        ]);

        $status = $result['Certificate']['Status'] ?? 'UNKNOWN';

        $this->line($status);

        return 0;
    }

    protected function handleEmailInbox()
    {
        $config = $this->getValidLarasurfConfig();

        if (!$config) {
            return 1;
        }

        $environment = $this->argument('environment');

        if (!$this->validateEnvironmentExistsInConfig($config, $environment)) {
            return 1;
        }

        $client = $this->getSesClient($config, $environment);

        if (!$client) {
            return 1;
        }

        $result = $client->verifyDomainIdentity([
            'Domain' => '<string>', // todo
        ]);

        return 0;
    }

    protected function handleEmailOutbox()
    {
        $config = $this->getValidLarasurfConfig();

        if (!$config) {
            return 1;
        }

        $environment = $this->argument('environment');

        if (!$this->validateEnvironmentExistsInConfig($config, $environment)) {
            return 1;
        }

        // todo

        return 0;
    }

    protected function validateDomainInConfig($config, $environment)
    {
        if (empty($config['cloud-environments'][$environment]['domain'])) {
            $this->error("Domain not set for environment '$environment' in larasurf.json");

            return false;
        }

        return true;
    }

    protected function ensureHostedZoneIdInConfig(&$config, $environment)
    {
        if (empty($config['cloud-environments'][$environment]['domain'])) {
            $this->error("Domain not set for environment '$environment' in larasurf.json");

            return false;
        }

        if (empty($config['cloud-environments'][$environment]['aws-hosted-zone-id'])) {
            $valid = Str::contains($config['cloud-environments'][$environment]['domain'], '.') &&
                strtolower($config['cloud-environments'][$environment]['domain']) === $config['cloud-environments'][$environment]['domain'];

            if (!$valid) {
                $this->error("Invalid domain set for environment '$environment' in larasurf.json");

                return false;
            }

            $client = $this->getRoute53Client($config, $environment);

            if (!$client) {
                return false;
            }

            $this->info('Updating Hosted Zone ID in larasurf.json');

            // todo: support more than 100 hosted zones
            $hosted_zones = $client->listHostedZones();

            $suffix = Str::afterLast($config['cloud-environments'][$environment]['domain'], '.');
            $domain_length = strlen($config['cloud-environments'][$environment]['domain']) - strlen($suffix) - 1;
            $domain = substr($config['cloud-environments'][$environment]['domain'], 0, $domain_length);

            if (Str::contains($domain, '.')) {
                $domain = Str::afterLast($domain, '.');
            }

            $domain .= '.' . $suffix;

            foreach ($hosted_zones['HostedZones'] as $hosted_zone) {
                if ($hosted_zone['Name'] === $domain . '.') {
                    $config['cloud-environments'][$environment]['aws-hosted-zone-id'] = str_replace('/hostedzone/', '', $hosted_zone['Id']);

                    return $this->writeLaraSurfConfig($config);
                }
            }

            $this->error("No hosted zone matching root domain '$domain' found.");

            return false;
        }

        return true;
    }

    protected function createStack($config, $environment)
    {
        $client = $this->getCloudFormationClient($config, $environment);

        if (!$client) {
            return false;
        }

        $stack_name = $this->getCloudFormationStackName($config, $environment);

        if (!$stack_name) {
            return false;
        }

        $infrastructure_template_path = base_path('.cloudformation/infrastructure.yml');

        if (!File::exists($infrastructure_template_path)) {
            $this->error("File '.cloudformation/infrastructure.yml' does not exist");

            return false;
        }

        $template = File::get($infrastructure_template_path);

        $client->createStack([
            'Capabilities' => ['CAPABILITY_IAM'],
            'StackName' => $stack_name,
            'Parameters' => [
                [
                    'ParameterKey' => 'VpcName',
                    'ParameterValue' => "{$config['project-name']}-$environment",
                ],
            ],
            'Tags' => [
                [
                    'Key' => 'Project',
                    'Value' => $config['project-name'],
                ],
                [
                    'Key' => 'Environment',
                    'Value' => $environment,
                ],
            ],
            'TemplateBody' => $template,
        ]);

        $this->info('Stack creation initiated');

        $this->line("See https://console.aws.amazon.com/cloudformation/home?region={$config['cloud-environments'][$environment]['aws-region']} for more information");

        $finished = false;
        $success = false;
        $status = null;
        $tries = 0;
        $limit = 180;

        while (!$finished && $tries < $limit) {
            $result = $client->describeStacks([
                'StackName' => $stack_name,
            ]);

            if (isset($result['Stacks'][0]['StackStatus'])) {
                $status = $result['Stacks'][0]['StackStatus'];
                $finished = !str_ends_with($status, '_IN_PROGRESS');

                if ($finished) {
                    $success = $result['Stacks'][0]['StackStatus'] === 'CREATE_COMPLETE';
                } else {
                    $this->line('Stack creation is not yet finished, checking again in 10 seconds...');
                }
            } else {
                $this->warn('Unexpected response from AWS API, trying again in 10 seconds');
            }

            if (!$finished) {
                $bar = $this->output->createProgressBar(10);

                $bar->start();

                for ($i = 0; $i < 10; $i++) {
                    sleep(1);
                    $bar->advance();
                }

                $bar->finish();

                $this->newLine();
            }

            $tries++;
        }

        if ($tries >= $limit) {
            $this->error('Stack failed to be created within 30 minutes');

            return false;
        } else {
            if ($success) {
                $this->info('Stack created successfully');
            } else {
                $this->error("Stack creation failed with status: '$status'");
                $this->error("See https://console.aws.amazon.com/cloudformation/home?region={$config['cloud-environments'][$environment]['aws-region']} for more information");

                return false;
            }
        }

        return true;
    }

    protected function afterCreateStackUpdateParameters($config, $environment)
    {
        $ssm_client = $this->getSsmClient($config, $environment);

        if (!$ssm_client) {
            return false;
        }

        $path = $this->getSsmParameterPath($config, $environment);

        if (!$path) {
            return false;
        }

        $results = $ssm_client->getParametersByPath([
            'Path' => $path,
        ]);

        $app_key = 'base64:' . base64_encode(Encrypter::generateKey('AES-256-CBC'));

        $default_env_vars = [
            'APP_ENV' => $environment,
            'APP_KEY' => $app_key,
            'CACHE_DRIVER' => 'redis',
            'DB_CONNECTION' => 'mysql',
            'LOG_CHANNEL' => 'errorlog',
            'QUEUE_CONNECTION' => 'sqs',
        ];

        foreach ($default_env_vars as $key => $value) {
            $var_path = $this->getSsmParameterPath($config, $environment, $key);
            $exists = false;

            foreach ($results['Parameters'] as $parameter) {
                if ($parameter['Name'] === $var_path) {
                    $this->warn("Parameter $var_path already exists");
                    $exists = true;
                }
            }

            if (!$exists) {
                $ssm_client->putParameter([
                    'Name' => $var_path,
                    'Type' => 'SecureString',
                    'Value' => $value,
                    'Tags' => [
                        [
                            'Key' => 'Project',
                            'Value' => $config['project-name'],
                        ],
                        [
                            'Key' => 'Environment',
                            'Value' => $environment,
                        ],
                    ],
                ]);

                $this->info("Successfully set parameter '$var_path'");
            }

            $config['cloud-environments'][$environment]['variables'][] = $key;
        }

        $variables = array_values(array_unique($config['cloud-environments'][$environment]['variables']));

        sort($variables);

        $config['cloud-environments'][$environment]['variables'] = $variables;

        $success = $this->writeLaraSurfConfig($config);

        if (!$success) {
            return false;
        }

        return true;
    }
}
