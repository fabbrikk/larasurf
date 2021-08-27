<?php

namespace LaraSurf\LaraSurf\AwsClients;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaraSurf\LaraSurf\AwsClients\DataTransferObjects\PrefixListEntry;

class Ec2Client extends Client
{
    public function allowIpPrefixList(string $prefix_list_id, string $ip)
    {
        $result = $this->client->describeManagedPrefixLists([
            'PrefixListIds' => [
                $prefix_list_id,
            ],
        ]);

        $cidr_description = $this->cidrWithDescriptionFromIpArgument($ip);

        $this->client->modifyManagedPrefixList([
            'AddEntries' => [
                [
                    'Cidr' => $cidr_description['cidr'],
                    'Description' => $cidr_description['description'],
                ],
            ],
            'CurrentVersion' => $result['PrefixLists'][0]['Version'],
            'PrefixListId' => $prefix_list_id,
        ]);
    }

    public function revokeIpPrefixList(string $prefix_list_id, string $ip)
    {
        $result = $this->client->describeManagedPrefixLists([
            'PrefixListIds' => [
                $prefix_list_id,
            ],
        ]);

        $cidr_description = $this->cidrWithDescriptionFromIpArgument($ip);

        $this->client->modifyManagedPrefixList([
            'RemoveEntries' => [
                [
                    'Cidr' => $cidr_description['cidr'],
                ],
            ],
            'CurrentVersion' => $result['PrefixLists'][0]['Version'],
            'PrefixListId' => $prefix_list_id,
        ]);
    }

    public function listIpsPrefixList(string $prefix_list_id)
    {
        $results = $this->client->getManagedPrefixListEntries([
            'PrefixListId' => $prefix_list_id,
        ]);

        return array_map(function ($entry) {
            return new PrefixListEntry($entry);
        }, $results['Entries']);
    }

    public function waitForPrefixListUpdate(string $prefix_list_id, OutputStyle $output = null, string $wait_message = ''): bool
    {
        $client = $this->client;

        return $this->waitForFinish(10, 3, function (&$success) use ($client, $prefix_list_id) {
            $result = $client->describeManagedPrefixLists([
                'PrefixListIds' => [
                    $prefix_list_id,
                ]
            ]);

            if (isset($result['PrefixLists'][0]['State'])) {
                $status = $result['PrefixLists'][0]['State'];

                $finished = !Str::endsWith($status, 'in-progress');

                if ($finished) {
                    $success = true;

                    return true;
                }
            } else if (count($result['PrefixLists']) === 0) {
                $success = false; // prefix list doesn't exist
                return true;
            }

            return false;
        }, $output, $wait_message);
    }

    protected function makeClient(array $args): \Aws\Ec2\Ec2Client
    {
        return new \Aws\Ec2\Ec2Client($args);
    }

    protected function cidrWithDescriptionFromIpArgument($ip): array
    {
        if ($ip === 'me') {
            $my_ip = trim(Http::get('https://checkip.amazonaws.com')->body());
            $cidr = "$my_ip/32";
            $description = 'Private Access';
        } else if ($ip === 'public') {
            $cidr = '0.0.0.0/0';
            $description = 'Public Access';
        } else {
            $cidr = "$ip/32";
            $description = 'Private Access';
        }

        return [
            'cidr' => $cidr,
            'description' => $description,
        ];
    }
}
