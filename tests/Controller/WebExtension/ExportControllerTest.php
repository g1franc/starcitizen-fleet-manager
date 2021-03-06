<?php

namespace App\Tests\Controller\WebExtension;

use App\Entity\Fleet;
use App\Entity\User;
use App\Service\Citizen\InfosProvider\CitizenInfosProviderInterface;
use App\Tests\WebTestCase;

class ExportControllerTest extends WebTestCase
{
    /** @var User */
    private $user;

    public function setUp(): void
    {
        parent::setUp();
        $this->user = $this->doctrine->getRepository(User::class)->findOneBy(['nickname' => 'Ioni']);
    }

    /**
     * @group functional
     * @group api
     * @group webextension
     */
    public function testExportOptionsCors(): void
    {
        $this->client->request('OPTIONS', '/api/export');
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @group functional
     * @group api
     * @group webextension
     */
    public function testValidExport(): void
    {
        $jsonContent = <<<EOT
                [
                  {
                    "manufacturer": "Drake",
                    "name": "Cutlass Black",
                    "lti": true,
                    "warbond": true,
                    "package_id": "15109407",
                    "pledge": "Package - Origin 100i Starter Game Package Warbond",
                    "pledge_date": "April 28, 2018",
                    "cost": "$110.00 USD"
                  },
                  {
                    "manufacturer": "Tumbril",
                    "name": "Cyclone",
                    "lti": false,
                    "warbond": false,
                    "package_id": "15186605",
                    "pledge": "Standalone Ship - Tumbril Cyclone ",
                    "pledge_date": "May 15, 2018",
                    "cost": "$55.00 USD"
                  }
                ]
            EOT;

        $citizenInfosProvider = static::$container->get(CitizenInfosProviderInterface::class);
        $citizenInfosProvider->setCitizen($this->user->getCitizen());

        $this->client->xmlHttpRequest('POST', '/api/export', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->user->getApiToken(),
            'CONTENT_TYPE' => 'application/json',
        ], $jsonContent);

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $json = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNull($json['requestExtensionVersion']);

        /** @var Fleet $lastFleet */
        $lastFleet = $this->doctrine->getRepository(Fleet::class)->findOneBy(['owner' => $this->user->getCitizen()], ['version' => 'desc']);
        $this->assertSame(2, $lastFleet->getVersion());
        $this->assertCount(2, $lastFleet->getShips());
        $this->assertSame('Cutlass Black', $lastFleet->getShips()[0]->getName());
        $this->assertSame('e37c618b-3ec6-4d4d-92b6-5aed679962a2', $lastFleet->getShips()[0]->getGalaxyId()->toString());
        $this->assertSame('Cutlass Black', $lastFleet->getShips()[0]->getNormalizedName());
        $this->assertSame('Cyclone', $lastFleet->getShips()[1]->getName());
        $this->assertNull($lastFleet->getShips()[1]->getGalaxyId());
        $this->assertNull($lastFleet->getShips()[1]->getNormalizedName());

        $this->assertTrue($lastFleet->getId()->equals($this->user->getCitizen()->getLastFleet()->getId()), 'Last fleet is inconsistent.');
    }

    /**
     * @group functional
     * @group api
     * @group webextension
     */
    public function testVersionComparison(): void
    {
        $jsonContent = '[]';

        $citizenInfosProvider = static::$container->get(CitizenInfosProviderInterface::class);
        $citizenInfosProvider->setCitizen($this->user->getCitizen());

        $this->client->xmlHttpRequest('POST', '/api/export', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->user->getApiToken(),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_FME_VERSION' => '1.0.6',
        ], $jsonContent);

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $json = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('1.0.6', $json['requestExtensionVersion']);
        $this->assertTrue($json['needUpgradeVersion']);
    }
}
