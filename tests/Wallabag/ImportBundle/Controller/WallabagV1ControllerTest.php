<?php

namespace Tests\Wallabag\ImportBundle\Controller;

use Tests\Wallabag\CoreBundle\WallabagCoreTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class WallabagV1ControllerTest extends WallabagCoreTestCase
{
    public function testImportWallabag()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/import/wallabag-v1');

        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertEquals(1, $crawler->filter('form[name=upload_import_file] > button[type=submit]')->count());
        $this->assertEquals(1, $crawler->filter('input[type=file]')->count());
    }

    public function testImportWallabagWithRabbitEnabled()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $client->getContainer()->get('craue_config')->set('import_with_rabbitmq', 1);

        $crawler = $client->request('GET', '/import/wallabag-v1');

        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertEquals(1, $crawler->filter('form[name=upload_import_file] > button[type=submit]')->count());
        $this->assertEquals(1, $crawler->filter('input[type=file]')->count());

        $client->getContainer()->get('craue_config')->set('import_with_rabbitmq', 0);
    }

    public function testImportWallabagBadFile()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/import/wallabag-v1');
        $form = $crawler->filter('form[name=upload_import_file] > button[type=submit]')->form();

        $data = [
            'upload_import_file[file]' => '',
        ];

        $client->submit($form, $data);

        $this->assertEquals(200, $client->getResponse()->getStatusCode());
    }

    public function testImportWallabagWithRedisEnabled()
    {
        $this->checkRedis();
        $this->logInAs('admin');
        $client = $this->getClient();

        $client->getContainer()->get('craue_config')->set('import_with_redis', 1);

        $crawler = $client->request('GET', '/import/wallabag-v1');

        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertEquals(1, $crawler->filter('form[name=upload_import_file] > button[type=submit]')->count());
        $this->assertEquals(1, $crawler->filter('input[type=file]')->count());

        $form = $crawler->filter('form[name=upload_import_file] > button[type=submit]')->form();

        $file = new UploadedFile(__DIR__.'/../fixtures/wallabag-v1.json', 'wallabag-v1.json');

        $data = [
            'upload_import_file[file]' => $file,
        ];

        $client->submit($form, $data);

        $this->assertEquals(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();

        $this->assertGreaterThan(1, $body = $crawler->filter('body')->extract(['_text']));
        $this->assertContains('flashes.import.notice.summary', $body[0]);

        $this->assertNotEmpty($client->getContainer()->get('wallabag_core.redis.client')->lpop('wallabag.import.wallabag_v1'));

        $client->getContainer()->get('craue_config')->set('import_with_redis', 0);
    }

    public function testImportWallabagWithFile()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/import/wallabag-v1');
        $form = $crawler->filter('form[name=upload_import_file] > button[type=submit]')->form();

        $file = new UploadedFile(__DIR__.'/../fixtures/wallabag-v1.json', 'wallabag-v1.json');

        $data = [
            'upload_import_file[file]' => $file,
        ];

        $client->submit($form, $data);

        $this->assertEquals(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();

        $content = $client->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('WallabagCoreBundle:Entry')
            ->findByUrlAndUserId(
                'http://www.framablog.org/index.php/post/2014/02/05/Framabag-service-libre-gratuit-interview-developpeur',
                $this->getLoggedInUserId()
            );

        $tag = $client->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('WallabagCoreBundle:Tag')
            ->findOneByLabel('Framabag');

        $this->assertTrue($content->getTags()->contains($tag));

        $this->assertGreaterThan(1, $body = $crawler->filter('body')->extract(['_text']));
        $this->assertContains('flashes.import.notice.summary', $body[0]);

        $this->assertEmpty($content->getMimetype(), 'Mimetype for http://www.framablog.org is empty');
        $this->assertEmpty($content->getPreviewPicture(), 'Preview picture for http://www.framablog.org is empty');
        $this->assertEmpty($content->getLanguage(), 'Language for http://www.framablog.org is empty');
        $this->assertEquals(2, count($content->getTags()));
        $this->assertInstanceOf(\DateTime::class, $content->getCreatedAt());
    }

    public function testImportWallabagWithFileAndMarkAllAsRead()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/import/wallabag-v1');
        $form = $crawler->filter('form[name=upload_import_file] > button[type=submit]')->form();

        $file = new UploadedFile(__DIR__.'/../fixtures/wallabag-v1-read.json', 'wallabag-v1-read.json');

        $data = [
            'upload_import_file[file]' => $file,
            'upload_import_file[mark_as_read]' => 1,
        ];

        $client->submit($form, $data);

        $this->assertEquals(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();

        $content1 = $client->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('WallabagCoreBundle:Entry')
            ->findByUrlAndUserId(
                'http://gilbert.pellegrom.me/recreating-the-square-slider',
                $this->getLoggedInUserId()
            );

        $this->assertTrue($content1->isArchived());

        $content2 = $client->getContainer()
            ->get('doctrine.orm.entity_manager')
            ->getRepository('WallabagCoreBundle:Entry')
            ->findByUrlAndUserId(
                'https://www.wallabag.org/features/',
                $this->getLoggedInUserId()
            );

        $this->assertTrue($content2->isArchived());

        $this->assertGreaterThan(1, $body = $crawler->filter('body')->extract(['_text']));
        $this->assertContains('flashes.import.notice.summary', $body[0]);
    }

    public function testImportWallabagWithEmptyFile()
    {
        $this->logInAs('admin');
        $client = $this->getClient();

        $crawler = $client->request('GET', '/import/wallabag-v1');
        $form = $crawler->filter('form[name=upload_import_file] > button[type=submit]')->form();

        $file = new UploadedFile(__DIR__.'/../fixtures/test.txt', 'test.txt');

        $data = [
            'upload_import_file[file]' => $file,
        ];

        $client->submit($form, $data);

        $this->assertEquals(302, $client->getResponse()->getStatusCode());

        $crawler = $client->followRedirect();

        $this->assertGreaterThan(1, $body = $crawler->filter('body')->extract(['_text']));
        $this->assertContains('flashes.import.notice.failed', $body[0]);
    }
}
