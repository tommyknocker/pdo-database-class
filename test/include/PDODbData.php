<?php
return [
    'params' => [
        'type' => 'mysql',
        'host' => 'localhost',
        'dbname' => 'testdb',
        'username' => 'testuser',
        'password' => 'testpassword',
        'port' => 3306,
        'charset' => 'utf8'
    ],
    'prefix' => 'table_',
    'tables' => [
        'users' => [
            'login' => 'char(10) not null',
            'active' => 'bool default 0',
            'customerId' => 'int(10) not null',
            'firstName' => 'char(10) not null',
            'lastName' => 'char(10)',
            'password' => 'text not null',
            'createdAt' => 'datetime',
            'updatedAt' => 'datetime',
            'expires' => 'datetime',
            'loginCount' => 'int(10) default 0',
            'unique key' => 'login (login)'
        ],
        'products' => [
            'customerId' => 'int(10) not null',
            'userId' => 'int(10) not null',
            'productName' => 'char(50)'
        ]
    ],
    'data' => [
        'users' => [
            ['login' => 'user1',
                'customerId' => 10,
                'firstName' => 'John',
                'lastName' => 'Doe',
                'password' => $PDODb->func('SHA1(?)', ["secretpassword+salt"]),
                'createdAt' => $PDODb->now(),
                'updatedAt' => $PDODb->now(),
                'expires' => $PDODb->now('+1Y'),
                'loginCount' => $PDODb->inc()
            ],
            ['login' => 'user2',
                'customerId' => 10,
                'firstName' => 'Mike',
                'lastName' => null,
                'password' => $PDODb->func('SHA1(?)', ["secretpassword2+salt"]),
                'createdAt' => $PDODb->now(),
                'updatedAt' => $PDODb->now(),
                'expires' => $PDODb->now('+1Y'),
                'loginCount' => $PDODb->inc(2)
            ],
            ['login' => 'user3',
                'active' => true,
                'customerId' => 11,
                'firstName' => 'Pete',
                'lastName' => 'D',
                'password' => $PDODb->func('SHA1(?)', ["secretpassword2+salt"]),
                'createdAt' => $PDODb->now(),
                'updatedAt' => $PDODb->now(),
                'expires' => $PDODb->now('+1Y'),
                'loginCount' => $PDODb->inc(3)
            ]
        ],
        'products' => [
            ['customerId' => 1,
                'userId' => 1,
                'productName' => 'product1',
            ],
            ['customerId' => 1,
                'userId' => 1,
                'productName' => 'product2',
            ],
            ['customerId' => 1,
                'userId' => 1,
                'productName' => 'product3',
            ],
            ['customerId' => 1,
                'userId' => 2,
                'productName' => 'product4',
            ],
            ['customerId' => 1,
                'userId' => 2,
                'productName' => 'product5',
            ],
        ]
    ]
];
