<?php

namespace Pterodactyl\Http\Controllers\Api\Remote\Servers;

use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\Allocation;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\ServerTransfer;
use Illuminate\Database\ConnectionInterface;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Repositories\Eloquent\ServerRepository;
use Pterodactyl\Repositories\Wings\DaemonServerRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

class ServerTransferController extends Controller
{
    /**
     * ServerTransferController constructor.
     */
    public function __construct(
        private ConnectionInterface $connection,
        private ServerRepository $repository,
        private DaemonServerRepository $daemonServerRepository
    ) {
    }

    /**
     * The daemon notifies us about a transfer failure.
     *
     * @throws \Throwable
     */
    public function failure(string $uuid): JsonResponse
    {
        $server = $this->repository->getByUuid($uuid);
        $transfer = $server->transfer;
        if (is_null($transfer)) {
            throw new ConflictHttpException('Server is not being transferred.');
        }

        return $this->processFailedTransfer($transfer);
    }

    /**
     * The daemon notifies us about a transfer success.
     *
     * @throws \Throwable
     */
    public function success(string $uuid): JsonResponse
    {
        $server = $this->repository->getByUuid($uuid);
        $transfer = $server->transfer;
        if (is_null($transfer)) {
            throw new ConflictHttpException('Server is not being transferred.');
        }

        if ($server->nest_id == 6)
        {
            $oldAlias = "";
            $newAlias = "";
            $oldPort = 0;
            $newPort = 0;
            foreach ($server->allocations as $allocation) {
                $oldAlias = $allocation->ip_alias;
                $oldPort = $allocation->port;
                break;
            }

            foreach ($transfer->newNode->allocations as $allocation)
            {
                $newAlias = $allocation->ip_alias;
                $newPort = $allocation->port;
                break;
            }

            $oldIp = $this->getIp($oldAlias);
            $newIp = $this->getIp($newAlias);

            $this->sendRequest($oldIp, $oldPort, $newIp, $newPort);
        }

        /** @var \Pterodactyl\Models\Server $server */
        $server = $this->connection->transaction(function () use ($server, $transfer) {
            $allocations = array_merge([$transfer->old_allocation], $transfer->old_additional_allocations);

            // Remove the old allocations for the server and re-assign the server to the new
            // primary allocation and node.
            Allocation::query()->whereIn('id', $allocations)->update(['server_id' => null]);
            $server->update([
                'allocation_id' => $transfer->new_allocation,
                'node_id' => $transfer->new_node,
            ]);

            $server = $server->fresh();
            $server->transfer->update(['successful' => true]);

            return $server;
        });

        // Delete the server from the old node making sure to point it to the old node so
        // that we do not delete it from the new node the server was transferred to.
        try {
            $this->daemonServerRepository
                ->setServer($server)
                ->setNode($transfer->oldNode)
                ->delete();
        } catch (DaemonConnectionException $exception) {
            Log::warning($exception, ['transfer_id' => $server->transfer->id]);
        }

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Release all the reserved allocations for this transfer and mark it as failed in
     * the database.
     *
     * @throws \Throwable
     */
    protected function processFailedTransfer(ServerTransfer $transfer): JsonResponse
    {
        $this->connection->transaction(function () use (&$transfer) {
            $transfer->forceFill(['successful' => false])->saveOrFail();

            $allocations = array_merge([$transfer->new_allocation], $transfer->new_additional_allocations);
            Allocation::query()->whereIn('id', $allocations)->update(['server_id' => null]);
        });

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * @param string $newAlias
     * @return string
     */
    private function getIp(string $newAlias): string
    {
        /*
         * Populate this.
         */
        $aliasMap = array();

        if (array_key_exists($newAlias, $aliasMap))
            return $aliasMap[$newAlias];
        return "";
    }

    /**
     * @param string $oldIp
     * @param int $oldPort
     * @param string $newIp
     * @param int $newPort
     * @return void
     */
    private function sendRequest(string $oldIp, int $oldPort, string $newIp, int $newPort): void
    {
        $url = 'http://api.scpslgame.com/provider/manageserver.php';
        $data = array(
            'user' => '',
            'token' => '',
            'ip' => $oldIp,
            'port' => $oldPort,
            'action' => 'reassign',
            'newip' => $newIp,
            'newport' => $newPort,
        );

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($curl);
        $file = fopen("/var/www/pterodactyl/storage/logs/transfer.log", "a");
        fwrite($file, date("Y-m-d h:m:s", time()) . "\n");
        fwrite($file, "$oldIp:$oldPort -> $newIp:$newPort\n");
        fwrite($file, "$result\n");
        fclose($file);
    }
}
