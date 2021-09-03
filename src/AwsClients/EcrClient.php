<?php

namespace LaraSurf\LaraSurf\AwsClients;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Str;
use LaraSurf\LaraSurf\AwsClients\DataTransferObjects\DnsRecord;
use LaraSurf\LaraSurf\Exceptions\AwsClients\ExpectedArrayOfTypeException;

class EcrClient extends Client
{
    public function repositoryUri(string $repository_name)
    {
        $result = $this->client->describeRepositories([
            'repositoryNames' => [$repository_name],
        ]);

        return $result['repositories'][0]['repositoryUri'] ?? false;
    }

    public function createRepository(string $repository_name): string
    {
        $result = $this->client->createRepository([
            'imageTagMutability' => 'IMMUTABLE',
            'repositoryName' => $repository_name,
            'tags' => $this->resourceTags(),
        ]);

        return $result['repository']['repositoryUri'];
    }

    public function deleteRepository(string $repository_name)
    {
        $this->client->deleteRepository([
            'force' => true,
            'repositoryName' => $repository_name,
        ]);
    }

    // todo: describeImages by tag to see if commit exists for deployments

    protected function makeClient(array $args): \Aws\Ecr\EcrClient
    {
        return new \Aws\Ecr\EcrClient($args);
    }
}
