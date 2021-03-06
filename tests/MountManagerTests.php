<?php

use League\Flysystem\Filesystem;
use League\Flysystem\MountManager;

class MountManagerTests extends PHPUnit_Framework_TestCase
{
    public function testInstantiable()
    {
        $manager = new MountManager();
    }

    public function testConstructorInjection()
    {
        $mock = Mockery::mock('League\Flysystem\FilesystemInterface');
        $manager = new MountManager([
            'prefix' => $mock,
        ]);
        $this->assertEquals($mock, $manager->getFilesystem('prefix'));
    }

    /**
     * @expectedException  InvalidArgumentException
     */
    public function testInvalidPrefix()
    {
        $manager = new MountManager();
        $manager->mountFilesystem(false, Mockery::mock('League\Flysystem\FilesystemInterface'));
    }

    /**
     * @expectedException  LogicException
     */
    public function testUndefinedFilesystem()
    {
        $manager = new MountManager();
        $manager->getFilesystem('prefix');
    }

    public function invalidCallProvider()
    {
        return [
            [[], 'LogicException'],
            [[false], 'InvalidArgumentException'],
            [['path/without/protocol'], 'InvalidArgumentException'],
        ];
    }

    /**
     * @dataProvider  invalidCallProvider
     */
    public function testInvalidArguments($arguments, $exception)
    {
        $this->setExpectedException($exception);
        $manager = new MountManager();
        $manager->filterPrefix($arguments);
    }

    public function testCallForwarder()
    {
        $manager = new MountManager();
        $mock = Mockery::mock('League\Flysystem\FilesystemInterface');
        $mock->shouldReceive('aMethodCall')->once()->andReturn('a result');
        $manager->mountFilesystem('prot', $mock);
        $this->assertEquals($manager->aMethodCall('prot://file.ext'), 'a result');
    }

    public function testCopyBetweenFilesystems()
    {
        $manager = new MountManager();
        $fs1 = Mockery::mock('League\Flysystem\FilesystemInterface');
        $fs2 = Mockery::mock('League\Flysystem\FilesystemInterface');
        $manager->mountFilesystem('fs1', $fs1);
        $manager->mountFilesystem('fs2', $fs2);

        $filename = 'test.txt';
        $buffer = tmpfile();
        $fs1->shouldReceive('readStream')->once()->with($filename)->andReturn($buffer);
        $fs2->shouldReceive('writeStream')->once()->with($filename, $buffer)->andReturn(true);
        $response = $manager->copy("fs1://{$filename}", "fs2://{$filename}");
        $this->assertTrue($response);

        // test failed status
        $fs1->shouldReceive('readStream')->once()->with($filename)->andReturn(false);
        $status = $manager->copy("fs1://{$filename}", "fs2://{$filename}");
        $this->assertFalse($status);

        $buffer = tmpfile();
        $fs1->shouldReceive('readStream')->once()->with($filename)->andReturn($buffer);
        $fs2->shouldReceive('writeStream')->once()->with($filename, $buffer)->andReturn(false);
        $status = $manager->copy("fs1://{$filename}", "fs2://{$filename}");
        $this->assertFalse($status);

        $buffer = tmpfile();
        $fs1->shouldReceive('readStream')->once()->with($filename)->andReturn($buffer);
        $fs2->shouldReceive('writeStream')->once()->with($filename, $buffer)->andReturn(true);
        $status = $manager->copy("fs1://{$filename}", "fs2://{$filename}");
        $this->assertTrue($status);
    }

    public function testMoveBetweenFilesystems()
    {
        $manager = Mockery::mock('League\Flysystem\MountManager')->makePartial();
        $fs1 = Mockery::mock('League\Flysystem\FilesystemInterface');
        $fs2 = Mockery::mock('League\Flysystem\FilesystemInterface');
        $manager->mountFilesystem('fs1', $fs1);
        $manager->mountFilesystem('fs2', $fs2);

        $filename = 'test.txt';
        $buffer = tmpfile();
        $fs1->shouldReceive('readStream')->with($filename)->andReturn($buffer);
        $fs2->shouldReceive('writeStream')->with($filename, $buffer)->andReturn(false);
        $code = $manager->move("fs1://{$filename}", "fs2://{$filename}");
        $this->assertFalse($code);

        $manager->shouldReceive('copy')->with("fs1://{$filename}", "fs2://{$filename}")->andReturn(true);
        $manager->shouldReceive('delete')->with("fs1://{$filename}")->andReturn(true);
        $code = $manager->move("fs1://{$filename}", "fs2://{$filename}");

        $this->assertTrue($code);
    }

    protected function mockFileIterator()
    {
        $file = Mockery::mock('\SplFileInfo', [
            'getPathname' => 'path/file/test',
            'getFilename' => 'test',
            'getType' => 'file',
            'getSize' => 12361863,
            'getMTime' => (new \DateTime())->format('U'),
        ], ['test']);

        return [$file];
    }

    protected function mockHugeFileIterator()
    {
        $file = Mockery::mock('\SplFileInfo', [
            'getPathname' => 'path/file/test',
            'getFilename' => 'test',
            'getType' => 'file',
            'getSize' => 12361863,
            'getMTime' => (new \DateTime())->format('U'),
        ], ['test']);

        return array_fill(0, 1000, $file);
    }

    protected function mockLocalAdapter($which = 'small')
    {
        $localAdapter = Mockery::mock('\League\Flysystem\Adapter\Local');
        $localAdapter->makePartial();
        $localAdapter->shouldAllowMockingProtectedMethods();

        $localAdapter->shouldReceive('getDirectoryIterator')->andReturn(
            $which == 'small' ? $this->mockFileIterator() : $this->mockHugeFileIterator()
        );
        $localAdapter->shouldReceive('getFilePath')->andReturnUsing(function ($file) {
            return $file->getPathname();
        });

        return $localAdapter;
    }

    protected function mockPassthruCache()
    {
        $cache = Mockery::mock('\League\Flysystem\Cache\Memory');
        $cache->makePartial();
        $cache->shouldReceive('isComplete')->andReturn(false);
        $cache->shouldReceive('storeContents')->andReturnUsing(function ($directory, $contents, $recursive) {
            return $contents;
        });

        return $cache;
    }

    public function testFileWithAliasWithMountManager()
    {
        $fs = new Filesystem($this->mockLocalAdapter('small'), $this->mockPassthruCache());
        $fs2 = new Filesystem($this->mockLocalAdapter('huge'), $this->mockPassthruCache());

        $mountManager = new MountManager();
        $mountManager->mountFilesystem('local', $fs);
        $mountManager->mountFilesystem('huge', $fs2);

        $results = $mountManager->listContents("local://tests/files");
        foreach ($results as $result) {
            $this->assertArrayHasKey('filesystem', $result);
            $this->assertEquals($result['filesystem'], 'local');
        }

        $results = $mountManager->listContents("huge://tests/files");
        foreach ($results as $result) {
            $this->assertArrayHasKey('filesystem', $result);
            $this->assertEquals($result['filesystem'], 'huge');
        }
    }
}
