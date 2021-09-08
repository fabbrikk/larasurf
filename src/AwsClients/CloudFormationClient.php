<?php

namespace LaraSurf\LaraSurf\AwsClients;

use Aws\Exception\AwsException;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LaraSurf\LaraSurf\Exceptions\AwsClients\TimeoutExceededException;
use League\Flysystem\FileNotFoundException;
use SebastianBergmann\CodeCoverage\Report\PHP;
use Symfony\Component\Console\Cursor;

class CloudFormationClient extends Client
{
    const STACK_STATUS_CREATE_COMPLETE = 'CREATE_COMPLETE';
    const STACK_STATUS_UPDATE_COMPLETE = 'UPDATE_COMPLETE';
    const STACK_STATUS_DELETED = 'DELETED';

    public function createStack(
        bool $is_enabled,
        string $domain,
        string $root_domain,
        string $hosted_zone_id,
        string $certificate_arn,
        int $db_storage_size,
        string $db_instance_class,
        string $db_username,
        string $db_password,
        string $cache_node_type,
        string $application_image,
        string $webserver_image,
        string $task_cpu,
        string $task_memory
    )
    {
        $this->validateEnvironmentIsSet();

        $this->client->createStack([
            'Capabilities' => ['CAPABILITY_IAM', 'CAPABILITY_NAMED_IAM'],
            'StackName' => $this->stackName(),
            'Parameters' => [
                [
                    'ParameterKey' => 'Enabled',
                    'ParameterValue' => $is_enabled ? 'true' : 'false',
                ],
                [
                    'ParameterKey' => 'ProjectName',
                    'ParameterValue' => $this->project_name,
                ],
                [
                    'ParameterKey' => 'ProjectId',
                    'ParameterValue' => $this->project_id,
                ],
                [
                    'ParameterKey' => 'EnvironmentName',
                    'ParameterValue' => $this->environment,
                ],
                [
                    'ParameterKey' => 'DomainName',
                    'ParameterValue' => $domain,
                ],
                [
                    'ParameterKey' => 'RootDomainName',
                    'ParameterValue' => $root_domain,
                ],
                [
                    'ParameterKey' => 'HostedZoneId',
                    'ParameterValue' => $hosted_zone_id,
                ],
                [
                    'ParameterKey' => 'CertificateArn',
                    'ParameterValue' => $certificate_arn,
                ],
                [
                    'ParameterKey' => 'DBStorageSize',
                    'ParameterValue' => (string) $db_storage_size,
                ],
                [
                    'ParameterKey' => 'DBInstanceClass',
                    'ParameterValue' => $db_instance_class,
                ],
                [
                    'ParameterKey' => 'DBAvailabilityZone',
                    'ParameterValue' => "{$this->aws_region}a",
                ],
                [
                    'ParameterKey' => 'DBVersion',
                    'ParameterValue' => '8.0.25',
                ],
                [
                    'ParameterKey' => 'DBMasterUsername',
                    'ParameterValue' => $db_username,
                ],
                [
                    'ParameterKey' => 'DBMasterPassword',
                    'ParameterValue' => $db_password,
                ],
                [
                    'ParameterKey' => 'CacheNodeType',
                    'ParameterValue' => $cache_node_type,
                ],
                [
                    'ParameterKey' => 'ApplicationImage',
                    'ParameterValue' => $application_image,
                ],
                [
                    'ParameterKey' => 'WebserverImage',
                    'ParameterValue' => $webserver_image,
                ],
                [
                    'ParameterKey' => 'TaskDefinitionCpu',
                    'ParameterValue' => $task_cpu,
                ],
                [
                    'ParameterKey' => 'TaskDefinitionMemory',
                    'ParameterValue' => $task_memory,
                ],
            ],
            'Tags' => $this->resourceTags(),
            'TemplateBody' => $this->template(),
        ]);
    }

    public function updateStack(
        bool $is_enabled,
        array $secrets = [],
        ?string $domain = null,
        ?string $root_domain = null,
        ?string $hosted_zone_id = null,
        ?string $certificate_arn = null,
        ?int $db_storage_size = null,
        ?string $db_instance_class = null,
        ?string $cache_node_type = null,
        ?string $task_cpu = null,
        ?string $task_memory = null
    )
    {
        $update_params = [];

        foreach ([
                     'Enabled' => $is_enabled ? 'true' : 'false',
                     'DomainName' => $domain,
                     'RootDomainName' => $root_domain,
                     'HostedZoneId' => $hosted_zone_id,
                     'CertificateArn' => $certificate_arn,
                     'DBStorageSize' => $db_storage_size,
                     'DBInstanceClass' => $db_instance_class,
                     'CacheNodeType' => $cache_node_type,
                     'TaskDefinitionCpu' => $task_cpu,
                     'TaskDefinitionMemory' => $task_memory,
                 ] as $key => $value) {
            if ($value !== null) {
                $update_params[] = [
                    'ParameterKey' => $key,
                    'ParameterValue' => $value,
                ];
            } else {
                $update_params[] = [
                    'ParameterKey' => $key,
                    'UsePreviousValue' => true,
                ];
            }
        }

        $this->client->updateStack([
            'Capabilities' => ['CAPABILITY_IAM', 'CAPABILITY_NAMED_IAM'],
            'StackName' => $this->stackName(),
            'Parameters' => [
                [
                    'ParameterKey' => 'ProjectName',
                    'UsePreviousValue' => true,
                ],
                [
                    'ParameterKey' => 'ProjectId',
                    'UsePreviousValue' => true,
                ],
                [
                    'ParameterKey' => 'EnvironmentName',
                    'UsePreviousValue' => true,
                ],
                [
                    'ParameterKey' => 'DBAvailabilityZone',
                    'UsePreviousValue' => true,
                ],
                [
                    'ParameterKey' => 'DBVersion',
                    'UsePreviousValue' => true,
                ],
                [
                    'ParameterKey' => 'DBMasterUsername',
                    'UsePreviousValue' => true,
                ],
                [
                    'ParameterKey' => 'DBMasterPassword',
                    'UsePreviousValue' => true,
                ],
                [
                    'ParameterKey' => 'ApplicationImage',
                    'UsePreviousValue' => true,
                ],
                [
                    'ParameterKey' => 'WebserverImage',
                    'UsePreviousValue' => true,
                ],
                ...$update_params,
            ],
            'TemplateBody' => $this->template($secrets),
        ]);
    }

    public function deleteStack()
    {
        $this->client->deleteStack([
            'StackName' => $this->stackName(),
        ]);
    }

    public function stackStatus(): string|false
    {
        try {
            $result = $this->client->describeStacks([
                'StackName' => $this->stackName(),
            ]);
        } catch (AwsException $e) {
            //
        }

        if (empty($result['Stacks'][0])) {
            return false;
        }

        return $result['Stacks'][0]['StackStatus'] ?? false;
    }

    public function stackOutput(array|string $keys): array|string|false
    {
        $array_keys = (array) $keys;

        $result = $this->client->describeStacks([
            'StackName' => $this->stackName(),
        ]);

        $keyed_values = [];

        if (isset($result['Stacks'][0]['Outputs'])) {
            foreach ($result['Stacks'][0]['Outputs'] as $output) {
                if (in_array($output['OutputKey'], $array_keys)) {
                    $keyed_values[$output['OutputKey']] = $output['OutputValue'];
                }
            }
        }

        return is_array($keys) ? $keyed_values : ($keyed_values[$keys] ?? false);
    }

    public function waitForStackInfoPanel(string $success_status, OutputStyle $output = null, string $word = null, bool $can_exit = true): array
    {
        $finished = false;
        $tries = 0;
        $success = false;
        $limit = 60;
        $wait_seconds = 60;
        $status = null;

        if ($output) {
            $word_padding = str_repeat(' ', strlen($word) - 7);

            $footer = $can_exit
                ? 'If you do not wish to wait, you can safely exit this screen with Ctrl+C'
                : 'Please do not exit this screen! There are still things to do after this.';

            $message =
                "╔══════════════════════════════════════════════════════════════════════════════╗" . PHP_EOL .
                "║                                                                              ║" . PHP_EOL .
                "║                 <info>Your CloudFormation stack is being $word!</info>$word_padding                  ║" . PHP_EOL .
                "║                                                                              ║" . PHP_EOL .
                "║           <info>You can view the progress of your stack operation here:</info>            ║" . PHP_EOL .
                "║      https://console.aws.amazon.com/cloudformation/home?region={$this->aws_region}     ║" . PHP_EOL .
                "║                                                                              ║" . PHP_EOL .
                "╠══════════════════════════════════════════════════════════════════════════════╣" . PHP_EOL .
                "║                                                                              ║" . PHP_EOL .
                "║         <info>This would also be a great time to review the documentation!</info>         ║" . PHP_EOL .
                "║                         https://larasurf.com/docs                            ║" . PHP_EOL .
                "║                                                                              ║" . PHP_EOL .
                "╚══════════════════════════════════════════════════════════════════════════════╝" . PHP_EOL .
                PHP_EOL .
                $footer . PHP_EOL .
                PHP_EOL .
                "<info>Checking for updates every 60 seconds...</info>" . PHP_EOL;

            $output->writeln($message);
        }

        while (!$finished && $tries < $limit) {
            try {
                $result = $this->client->describeStacks([
                    'StackName' => $this->stackName(),
                ]);

                if (isset($result['Stacks'][0]['StackStatus'])) {
                    $status = $result['Stacks'][0]['StackStatus'];
                    $finished = !str_ends_with($status, '_IN_PROGRESS');

                    if ($finished) {
                        $success = $status === $success_status;
                    }
                }
            } catch (AwsException $e) {
                $finished = true;
                $status = 'DELETED';
                $success = $success_status === $status;
            }

            if (!$finished && $output) {
                for ($i = 1; $i <= $wait_seconds; $i++) {
                    $bars = str_repeat('=', $i);
                    $empty = str_repeat('-', $wait_seconds - $i);

                    $seconds = $wait_seconds - $i;

                    $cursor = new Cursor($output);
                    $cursor->moveToColumn(0);

                    $output->write("[<info>$bars</info>$empty] {$seconds} seconds... ");

                    sleep(1);
                }
            } else if (!$finished) {
                sleep($wait_seconds);
            }

            $tries++;
        }

        if ($tries >= $limit) {
            throw new TimeoutExceededException($tries * $limit);
        }

        if ($output) {
            $output->newLine();
            $output->write("\x07");
        }

        return [
            'success' => $success,
            'status' => $status,
        ];
    }

    public static function templatePath(): string
    {
        return base_path('.cloudformation/infrastructure.yml');
    }

    protected function makeClient(array $args): \Aws\CloudFormation\CloudFormationClient
    {
        return new \Aws\CloudFormation\CloudFormationClient($args);
    }

    protected function stackName()
    {
        $this->validateEnvironmentIsSet();

        return $this->project_name . '-' . $this->project_id . '-' . $this->environment;
    }

    protected function template(array $secrets = []): string
    {
        $path = static::templatePath();

        if (!File::exists($path)) {
            throw new FileNotFoundException($path);
        }

        $contents = File::get($path);

        if ($secrets) {
            $replace = '          Secrets:' . PHP_EOL;

            foreach ($secrets as $name => $arn) {
                $replace .=
                    "            - Name: $name" . PHP_EOL .
                    "              ValueFrom: $arn" . PHP_EOL;
            }
        } else {
            $replace = '';
        }

        return Str::replace('          Secrets: #LARASURF_SECRETS#', $replace, $contents);
    }
}
