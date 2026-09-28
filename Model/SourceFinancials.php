<?php
declare(strict_types=1);
namespace Venuno\OrderImport\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\LocalizedException;
use Venuno\OrderImport\Api\SourceFinancialsInterface;
use Venuno\OrderImport\Api\Data\SourceFinancialsResultInterface;
use Venuno\OrderImport\Api\Data\SourceFinancialsResultInterfaceFactory;

class SourceFinancials implements SourceFinancialsInterface
{
    public function __construct(
        private readonly TokenAuthenticator $authenticator,
        private readonly DeploymentConfig $config,
        private readonly SourceFinancialsReader $reader,
        private readonly SourceFinancialsResultInterfaceFactory $resultFactory
    ) {}

    public function get(int $orderId): SourceFinancialsResultInterface
    {
        $this->authenticator->authenticate();
        $source=$this->config->get('venuno/order_import/source_financials');
        $target=$this->config->get('db/connection/default');
        // Disabled by default. The database can ONLY be selected by protected server configuration.
        if (!is_array($source) || ($source['enabled']??false)!==true || !is_array($target)
            || !is_string($source['database']??null) || !preg_match('/^[a-zA-Z0-9_]+$/D',$source['database'])
            || $source['database']===($target['dbname']??null) || $orderId<1) {
            throw new LocalizedException(__('The read-only financial source is not configured or the request is invalid.'));
        }
        try {
            $db=new \PDO('mysql:host='.$target['host'].';dbname='.$source['database'].';charset=utf8mb4',
                $target['username'],$target['password'],[
                    \PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_EMULATE_PREPARES=>false,
                    \PDO::ATTR_TIMEOUT=>5,
                    \PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false
                ]);
            $snapshot=$this->reader->read($db,$orderId);
            $snapshot['source_base_url']=$source['base_url']??'';
            return $this->resultFactory->create()->setSnapshotJson(json_encode($snapshot,JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            // Do not expose SQL, database credentials or internals through the REST error handler.
            throw new LocalizedException(__('The read-only source snapshot is unavailable; no import is safe.'));
        }
    }
}
