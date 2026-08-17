<?php
declare(strict_types=1);
return ['dsn'=>'sqlite:'.dirname(__DIR__,2).'/database/store.sqlite','user'=>null,'password'=>null,'jwt_secret'=>getenv('STORE_JWT_SECRET') ?: 'change-this-local-secret'];
