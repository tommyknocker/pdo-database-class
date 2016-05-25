<?php

/**
 * PDODb class testing
 *
 * @author    Vasiliy A. Ulin <ulin.vasiliy@gmail.com>
 * @license http://www.gnu.org/licenses/lgpl.txt LGPLv3
 */
class PDODbTest extends PHPUnit_Framework_TestCase
{
    /**
     * Test data
     * @var array
     */
    private $data = [];

    /**
     * PDODbHelper instance
     * @var PDODbHelper
     */
    private $helper = null;

    public function __construct()
    {
        $PDODb        = new PDODb('mysql');
        $this->data   = include('include/PDODbData.php');
        require_once('include/PDODbHelper.php');
        $this->helper = new PDODbHelper();
        parent::__construct();
    }

    public function testInitByArray()
    {
        $PDODb = new PDODb($this->data['params']);
        $this->assertInstanceOf('PDO', $PDODb->pdo());
    }

    public function testInitByObject()
    {
        $object = new PDO($this->data['params']['type'].':'
            .'host='.$this->data['params']['host'].';'
            .'dbname='.$this->data['params']['dbname'].';'
            .'port='.$this->data['params']['port'].';'
            .'charset='.$this->data['params']['charset'], $this->data['params']['username'], $this->data['params']['password']);
        $PDODb  = new PDODb($object);
        $this->assertInstanceOf('PDO', $PDODb->pdo());
    }

    public function testInitByParams()
    {
        $PDODb = new PDODb($this->data['params']['type'], $this->data['params']['host'], $this->data['params']['username'], $this->data['params']['password'],
            $this->data['params']['dbname'], $this->data['params']['port'], $this->data['params']['charset']);
        $this->assertInstanceOf('PDO', $PDODb->pdo());
    }

    public function testGetInstance()
    {
        $PDODb = PDODb::getInstance();
        $this->assertInstanceOf('PDODb', $PDODb);
        $this->assertInstanceOf('PDO', $PDODb->pdo());
    }

    public function testTableCreationAndExistance()
    {
        $PDODb = PDODb::getInstance();
        foreach ($this->data['tables'] as $name => $fields) {
            $this->helper->createTable($this->data['prefix'].$name, $fields);
            $this->assertTrue($PDODb->tableExists($this->data['prefix'].$name));
        }
    }

    public function testInsert()
    {
        $PDODb = PDODb::getInstance();
        $PDODb->setPrefix($this->data['prefix']);
        foreach ($this->data['data'] as $tableName => $tableData) {
            foreach ($tableData as $row) {
                $lastInsertId = $PDODb->insert($tableName, $row);
                $this->assertInternalType('int', $lastInsertId);
            }
        }

        // bad insert test
        $badUser = ['login' => null,
            'customerId' => 10,
            'firstName' => 'John',
            'lastName' => 'Doe',
            'password' => 'test',
            'createdAt' => $PDODb->now(),
            'updatedAt' => $PDODb->now(),
            'expires' => $PDODb->now('+1Y'),
            'loginCount' => $PDODb->inc()
        ];

        $lastInsertId = $PDODb->insert('users', $badUser);
        $this->assertFalse($lastInsertId);
    }
}