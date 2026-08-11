<?php
namespace exface\UrlDataConnector;

use exface\Core\CommonLogic\Model\App;
use exface\Core\Facades\AbstractHttpFacade\HttpFacadeInstaller;
use exface\Core\Factories\FacadeFactory;
use exface\Core\Interfaces\InstallerInterface;
use exface\UrlDataConnector\Facades\SwaggerUiFacade;

class UrlDataConnectorApp extends App
{
    public function getInstaller(InstallerInterface $injected_installer = null)
    {
        $installer = parent::getInstaller($injected_installer);

        // Swagger UI facade to render a UI for any OpenAPI spec
        $tplInstaller = new HttpFacadeInstaller($this->getSelector());
        $tplInstaller->setFacade(FacadeFactory::createFromString(SwaggerUiFacade::class, $this->getWorkbench()));
        $installer->addInstaller($tplInstaller);

        return $installer;
    }
}