<?php

namespace LaraSurf\LaraSurf\Commands;

use Illuminate\Console\Command;
use LaraSurf\LaraSurf\CircleCI\Client;
use LaraSurf\LaraSurf\Commands\Traits\HasEnvironmentOption;
use LaraSurf\LaraSurf\Commands\Traits\HasSubCommands;
use LaraSurf\LaraSurf\Commands\Traits\InteractsWithAws;
use LaraSurf\LaraSurf\Commands\Traits\InteractsWithCircleCI;
use LaraSurf\LaraSurf\Constants\Cloud;

class CloudImages extends Command
{
    use HasSubCommands;
    use HasEnvironmentOption;
    use InteractsWithAws;
    use InteractsWithCircleCI;

    const REPOSITORY_TYPE_APPLICATION = 'application';
    const REPOSITORY_TYPE_WEBSERVER = 'webserver';

    const COMMAND_CREATE_REPOS = 'create-repos';
    const COMMAND_DELETE_REPOS = 'delete-repos';
    const COMMAND_REPO_URIS = 'repo-uris';

    protected $signature = 'larasurf:cloud-images
                            {--environment= : The environment: \'stage\' or \'production\'}
                            {subcommand : The subcommand to run: \'create-repos\', \'delete-repos\', or \'repo-uris\'}';

    protected $description = 'Manage images and image repositories in cloud environments';

    protected array $commands = [
        self::COMMAND_CREATE_REPOS => 'handleCreateRepo',
        self::COMMAND_DELETE_REPOS => 'handleDeleteRepo',
        self::COMMAND_REPO_URIS => 'handleRepoUris',
    ];

    public function handleCreateRepo()
    {
        $env = $this->environmentOption();

        if (!$env) {
            return 1;
        }

        $circleci_api_key = static::circleCIApiKey();

        if (!$circleci_api_key) {
            $this->error('Set a CircleCI API key first');

            return 1;
        }

        $circleci_project = $this->gitOriginUrl();

        $circleci = static::circleCI($circleci_api_key, $circleci_project);

        $this->info('Checking CircleCI environment variables...');

        $circleci_existing_vars = $this->circleCIExistingEnvironmentVariablesAskDelete($circleci);

        if ($circleci_existing_vars === false) {
            return 1;
        }

        $aws_region = $this->choice('In which region would you like to create the image repositories?', Cloud::AWS_REGIONS, 0);

        $this->info('Deleting CircleCI environment variables...');

        foreach ($circleci_existing_vars as $name) {
            $circleci->deleteEnvironmentVariable($name);
        }

        $ecr = $this->awsEcr($env, $aws_region);

        $this->info('Creating image repositories...');

        $uri_application = $ecr->createRepository($this->repositoryName($env, self::REPOSITORY_TYPE_APPLICATION));
        $uri_webserver = $ecr->createRepository($this->repositoryName($env, self::REPOSITORY_TYPE_WEBSERVER));

        $this->info('Repositories created successfully');
        $this->newLine();
        $this->info('Application image repository URI:');
        $this->getOutput()->writeln($uri_application);
        $this->newLine();
        $this->info('Webserver image repository URI:');
        $this->getOutput()->writeln($uri_webserver);

        $this->newLine();
        $this->info('Updating LaraSurf configuration...');

        static::config()->set("environments.$env.aws-region", $aws_region);

        if (!static::config()->write()) {
            $this->error('Failed to update LaraSurf configuration');

            return 1;
        }

        $this->info('Updated LaraSurf configuration successfully');

        return 0;
    }

    public function handleDeleteRepo()
    {
        $env = $this->environmentOption();

        if (!$env) {
            return 1;
        }

        $aws_region = static::config()->get("environments.$env.aws-region");

        if (!$aws_region) {
            $this->error('Environment AWS region not found in configuration file');

            return 1;
        }

        $circleci_api_key = static::circleCIApiKey();

        if ($circleci_api_key) {
            $circleci_project = $this->gitOriginUrl();

            $circleci = static::circleCI($circleci_api_key, $circleci_project);

            $this->info('Checking CircleCI environment variables...');

            $circleci_existing_vars = $this->circleCIExistingEnvironmentVariablesAskDelete($circleci);

            if ($circleci_existing_vars === false) {
                return 1;
            }

            $this->info('Deleting CircleCI environment variables...');

            foreach ($circleci_existing_vars as $name) {
                $circleci->deleteEnvironmentVariable($name);
            }
        }

        $cloudformation = $this->awsCloudFormation($env, $aws_region);

        if ($cloudformation->stackStatus()) {
            $this->error("Stack exists for '$env' environment; delete that first");

            return 1;
        }

        $ecr = $this->awsEcr($env);

        $ecr->deleteRepository($this->repositoryName($env, self::REPOSITORY_TYPE_APPLICATION));
        $ecr->deleteRepository($this->repositoryName($env, self::REPOSITORY_TYPE_WEBSERVER));

        $this->info('Successfully deleted both application and webserver image repositories');

        static::config()->set("environments.$env", null);

        if(!static::config()->write()) {
            $this->error('Failed to update LaraSurf configuration');

            return 1;
        }

        $this->info('Updated LaraSurf configuration successfully');

        return 0;
    }

    public function handleRepoUris()
    {
        $env = $this->environmentOption();

        if (!$env) {
            return 1;
        }

        $aws_region = static::config()->get("environments.$env.aws-region");

        if (!$aws_region) {
            $this->error('Environment AWS region not found in configuration file');

            return 1;
        }

        $ecr = $this->awsEcr($env);

        $application_uri = $ecr->repositoryUri($this->repositoryName($env, self::REPOSITORY_TYPE_APPLICATION));
        $webserver_uri = $ecr->repositoryUri($this->repositoryName($env, self::REPOSITORY_TYPE_WEBSERVER));

        if (!$application_uri || !$webserver_uri) {
            $this->error("Application and/or webserver repositories do not exist for the '$env' environment");

            return 1;
        }

        $this->info('Application image repository URI:');
        $this->getOutput()->writeln($application_uri);
        $this->newLine();
        $this->info('Websever image repository URI:');
        $this->getOutput()->writeln($webserver_uri);

        return 0;
    }

    protected function repositoryName(string $environment, string $type): string
    {
        return static::config()->get('project-name') . '-' . static::config()->get('project-id') . "-$environment/$type";
    }

    protected function circleCIExistingEnvironmentVariablesAskDelete(Client $circleci): array|false
    {
        $existing_circleci_vars = $circleci->listEnvironmentVariables();

        $exists = [];

        foreach ($existing_circleci_vars as $name => $value) {
            if (in_array($name, [
                'AWS_ACCESS_KEY_ID',
                'AWS_SECRET_ACCESS_KEY',
                'AWS_REGION',
                'AWS_ECR_URL_WEBSERVER',
                'AWS_ECR_URL_APPLICATION',
            ])) {
                $exists[] = $name;

                $this->warn("CircleCI environment variable '$name' already exists!");
            }
        }

        if ($exists && !$this->ask('Would you like to delete these CircleCI environment variables and proceed?', false)) {
            return false;
        }

        return $exists;
    }
}
