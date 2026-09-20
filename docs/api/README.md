# Azera MVC API

## Classes & Interfaces overview

### `Azera\Aop`

- [Advice](Aop_Advice.md) `Azera\Aop\Advice`
- [Advised](Aop_Advised.md) `Azera\Aop\Advised`
- [Cache](Aop_Cache.md) `Azera\Aop\Cache`
- [CacheInterceptor](Aop_CacheInterceptor.md) `Azera\Aop\CacheInterceptor`
- [InterceptorInterface](Aop_InterceptorInterface.md) `Azera\Aop\InterceptorInterface`
- [Log](Aop_Log.md) `Azera\Aop\Log`
- [LogInterceptor](Aop_LogInterceptor.md) `Azera\Aop\LogInterceptor`
- [Pipeline](Aop_Pipeline.md) `Azera\Aop\Pipeline`
- [ProxyFactory](Aop_ProxyFactory.md) `Azera\Aop\ProxyFactory`
- [Retry](Aop_Retry.md) `Azera\Aop\Retry`
- [RetryInterceptor](Aop_RetryInterceptor.md) `Azera\Aop\RetryInterceptor`
- [Transactional](Aop_Transactional.md) `Azera\Aop\Transactional`
- [TransactionalInterceptor](Aop_TransactionalInterceptor.md) `Azera\Aop\TransactionalInterceptor`

### `Azera`

- [AppContext](AppContext.md) `Azera\AppContext`
- [Exception](Exception.md) `Azera\Exception`

### `Azera\Boot`

- [BootstrapDiscovery](Boot_BootstrapDiscovery.md) `Azera\Boot\BootstrapDiscovery`
- [BootstrapProvider](Boot_BootstrapProvider.md) `Azera\Boot\BootstrapProvider`
- [BootstrapResolver](Boot_BootstrapResolver.md) `Azera\Boot\BootstrapResolver`
- [FileBridge](Boot_FileBridge.md) `Azera\Boot\FileBridge`

### `Azera\Cache`

- [ArrayCache](Cache_ArrayCache.md) `Azera\Cache\ArrayCache`
- [InvalidArgumentException](Cache_InvalidArgumentException.md) `Azera\Cache\InvalidArgumentException`
- [NullCache](Cache_NullCache.md) `Azera\Cache\NullCache`

### `Azera\Cli`

- [Console](Cli_Console.md) `Azera\Cli\Console`
- [Task](Cli_Task.md) `Azera\Cli\Task`

### `Azera\Cli\Tasks`

- [AboutTask](Cli_Tasks_AboutTask.md) `Azera\Cli\Tasks\AboutTask`
- [CacheTask](Cli_Tasks_CacheTask.md) `Azera\Cli\Tasks\CacheTask`
- [DbTask](Cli_Tasks_DbTask.md) `Azera\Cli\Tasks\DbTask`
- [MakeTask](Cli_Tasks_MakeTask.md) `Azera\Cli\Tasks\MakeTask`
- [MigrateTask](Cli_Tasks_MigrateTask.md) `Azera\Cli\Tasks\MigrateTask`
- [ModelTask](Cli_Tasks_ModelTask.md) `Azera\Cli\Tasks\ModelTask`
- [RoutesTask](Cli_Tasks_RoutesTask.md) `Azera\Cli\Tasks\RoutesTask`
- [ServeTask](Cli_Tasks_ServeTask.md) `Azera\Cli\Tasks\ServeTask`
- [TestTask](Cli_Tasks_TestTask.md) `Azera\Cli\Tasks\TestTask`

### `Azera\Config`

- [Config](Config_Config.md) `Azera\Config\Config`

### `Azera\Core`

- [Controller](Core_Controller.md) `Azera\Core\Controller`
- [Dispatcher](Core_Dispatcher.md) `Azera\Core\Dispatcher`
- [Exception](Core_Exception.md) `Azera\Core\Exception`
- [MiddlewareInterface](Core_MiddlewareInterface.md) `Azera\Core\MiddlewareInterface`
- [ResolvedRoute](Core_ResolvedRoute.md) `Azera\Core\ResolvedRoute`
- [Router](Core_Router.md) `Azera\Core\Router`
- [ViewEngine](Core_ViewEngine.md) `Azera\Core\ViewEngine`

### `Azera\Core\Engines\Adapters`

- [BladeAdapter](Core_Engines_Adapters_BladeAdapter.md) `Azera\Core\Engines\Adapters\BladeAdapter`
- [PlatesAdapter](Core_Engines_Adapters_PlatesAdapter.md) `Azera\Core\Engines\Adapters\PlatesAdapter`
- [TwigAdapter](Core_Engines_Adapters_TwigAdapter.md) `Azera\Core\Engines\Adapters\TwigAdapter`

### `Azera\Core\Engines`

- [ClarityEngine](Core_Engines_ClarityEngine.md) `Azera\Core\Engines\ClarityEngine`
- [NativeEngine](Core_Engines_NativeEngine.md) `Azera\Core\Engines\NativeEngine`

### `Azera\Core\Exceptions`

- [ActionNotFoundException](Core_Exceptions_ActionNotFoundException.md) `Azera\Core\Exceptions\ActionNotFoundException`
- [ControllerNotFoundException](Core_Exceptions_ControllerNotFoundException.md) `Azera\Core\Exceptions\ControllerNotFoundException`
- [InvalidControllerException](Core_Exceptions_InvalidControllerException.md) `Azera\Core\Exceptions\InvalidControllerException`

### `Azera\Db`

- [Condition](Db_Condition.md) `Azera\Db\Condition`
- [Database](Db_Database.md) `Azera\Db\Database`
- [DatabaseManager](Db_DatabaseManager.md) `Azera\Db\DatabaseManager`
- [Exception](Db_Exception.md) `Azera\Db\Exception`
- [ModelMapping](Db_ModelMapping.md) `Azera\Db\ModelMapping`
- [Paginator](Db_Paginator.md) `Azera\Db\Paginator`
- [Query](Db_Query.md) `Azera\Db\Query`
- [ResultSet](Db_ResultSet.md) `Azera\Db\ResultSet`
- [Sql](Db_Sql.md) `Azera\Db\Sql`
- [SqlCase](Db_SqlCase.md) `Azera\Db\SqlCase`
- [Statement](Db_Statement.md) `Azera\Db\Statement`

### `Azera\Db\Event`

- [DatabaseEvent](Db_Event_DatabaseEvent.md) `Azera\Db\Event\DatabaseEvent`
- [DatabaseExceptionOccurred](Db_Event_DatabaseExceptionOccurred.md) `Azera\Db\Event\DatabaseExceptionOccurred`
- [DatabaseOperationFailed](Db_Event_DatabaseOperationFailed.md) `Azera\Db\Event\DatabaseOperationFailed`
- [QueryExecuted](Db_Event_QueryExecuted.md) `Azera\Db\Event\QueryExecuted`
- [ReconnectAborted](Db_Event_ReconnectAborted.md) `Azera\Db\Event\ReconnectAborted`
- [ReconnectAttempt](Db_Event_ReconnectAttempt.md) `Azera\Db\Event\ReconnectAttempt`
- [Reconnected](Db_Event_Reconnected.md) `Azera\Db\Event\Reconnected`
- [ReconnectFailed](Db_Event_ReconnectFailed.md) `Azera\Db\Event\ReconnectFailed`
- [StatementExecuted](Db_Event_StatementExecuted.md) `Azera\Db\Event\StatementExecuted`
- [StatementPrepared](Db_Event_StatementPrepared.md) `Azera\Db\Event\StatementPrepared`
- [TransactionCommitted](Db_Event_TransactionCommitted.md) `Azera\Db\Event\TransactionCommitted`
- [TransactionRolledBack](Db_Event_TransactionRolledBack.md) `Azera\Db\Event\TransactionRolledBack`
- [TransactionStarted](Db_Event_TransactionStarted.md) `Azera\Db\Event\TransactionStarted`

### `Azera\Db\Exceptions`

- [TransactionLostException](Db_Exceptions_TransactionLostException.md) `Azera\Db\Exceptions\TransactionLostException`

### `Azera\Db\Resolver`

- [ChainResolver](Db_Resolver_ChainResolver.md) `Azera\Db\Resolver\ChainResolver`
- [LiteralResolver](Db_Resolver_LiteralResolver.md) `Azera\Db\Resolver\LiteralResolver`
- [MappingResolver](Db_Resolver_MappingResolver.md) `Azera\Db\Resolver\MappingResolver`
- [ModelResolver](Db_Resolver_ModelResolver.md) `Azera\Db\Resolver\ModelResolver`
- [ResolveException](Db_Resolver_ResolveException.md) `Azera\Db\Resolver\ResolveException`
- [TableResolver](Db_Resolver_TableResolver.md) `Azera\Db\Resolver\TableResolver`

### `Azera\Event`

- [EventDispatcher](Event_EventDispatcher.md) `Azera\Event\EventDispatcher`
- [NullEventDispatcher](Event_NullEventDispatcher.md) `Azera\Event\NullEventDispatcher`

### `Azera\Facade`

- [Db](Facade_Db.md) `Azera\Facade\Db`
- [Tx](Facade_Tx.md) `Azera\Facade\Tx`

### `Azera\Http`

- [Cookie](Http_Cookie.md) `Azera\Http\Cookie`
- [Cookies](Http_Cookies.md) `Azera\Http\Cookies`
- [Request](Http_Request.md) `Azera\Http\Request`
- [Response](Http_Response.md) `Azera\Http\Response`
- [Session](Http_Session.md) `Azera\Http\Session`
- [SessionMiddleware](Http_SessionMiddleware.md) `Azera\Http\SessionMiddleware`
- [UploadedFile](Http_UploadedFile.md) `Azera\Http\UploadedFile`

### `Azera\Lifecycle`

- [RequestScoped](Lifecycle_RequestScoped.md) `Azera\Lifecycle\RequestScoped`

### `Azera\Log`

- [NullLogger](Log_NullLogger.md) `Azera\Log\NullLogger`

### `Azera\Orm\Attribute`

- [BelongsTo](Orm_Attribute_BelongsTo.md) `Azera\Orm\Attribute\BelongsTo`
- [Column](Orm_Attribute_Column.md) `Azera\Orm\Attribute\Column`
- [Connection](Orm_Attribute_Connection.md) `Azera\Orm\Attribute\Connection`
- [Entity](Orm_Attribute_Entity.md) `Azera\Orm\Attribute\Entity`
- [HasMany](Orm_Attribute_HasMany.md) `Azera\Orm\Attribute\HasMany`
- [HasOne](Orm_Attribute_HasOne.md) `Azera\Orm\Attribute\HasOne`

### `Azera\Orm\Casting`

- [BoolCast](Orm_Casting_BoolCast.md) `Azera\Orm\Casting\BoolCast`
- [Cast](Orm_Casting_Cast.md) `Azera\Orm\Casting\Cast`
- [Casts](Orm_Casting_Casts.md) `Azera\Orm\Casting\Casts`
- [DateTimeCast](Orm_Casting_DateTimeCast.md) `Azera\Orm\Casting\DateTimeCast`
- [EnumCast](Orm_Casting_EnumCast.md) `Azera\Orm\Casting\EnumCast`
- [FastFloatCast](Orm_Casting_FastFloatCast.md) `Azera\Orm\Casting\FastFloatCast`
- [FastIntCast](Orm_Casting_FastIntCast.md) `Azera\Orm\Casting\FastIntCast`
- [FloatCast](Orm_Casting_FloatCast.md) `Azera\Orm\Casting\FloatCast`
- [IntCast](Orm_Casting_IntCast.md) `Azera\Orm\Casting\IntCast`
- [JsonCast](Orm_Casting_JsonCast.md) `Azera\Orm\Casting\JsonCast`
- [PgArrayCast](Orm_Casting_PgArrayCast.md) `Azera\Orm\Casting\PgArrayCast`

### `Azera\Orm`

- [Document](Orm_Document.md) `Azera\Orm\Document`
- [EntityManager](Orm_EntityManager.md) `Azera\Orm\EntityManager`
- [FastHydrator](Orm_FastHydrator.md) `Azera\Orm\FastHydrator`
- [Heap](Orm_Heap.md) `Azera\Orm\Heap`
- [HydrationMap](Orm_HydrationMap.md) `Azera\Orm\HydrationMap`
- [JoinedResultSet](Orm_JoinedResultSet.md) `Azera\Orm\JoinedResultSet`
- [Metadata](Orm_Metadata.md) `Azera\Orm\Metadata`
- [Model](Orm_Model.md) `Azera\Orm\Model`
- [Node](Orm_Node.md) `Azera\Orm\Node`
- [RowSplitter](Orm_RowSplitter.md) `Azera\Orm\RowSplitter`

### `Azera\Orm\Storage`

- [MongoStore](Orm_Storage_MongoStore.md) `Azera\Orm\Storage\MongoStore`
- [PdoStore](Orm_Storage_PdoStore.md) `Azera\Orm\Storage\PdoStore`
- [Store](Orm_Storage_Store.md) `Azera\Orm\Storage\Store`
- [Stores](Orm_Storage_Stores.md) `Azera\Orm\Storage\Stores`

### `Azera\Queue`

- [Job](Queue_Job.md) `Azera\Queue\Job`
- [JobInterface](Queue_JobInterface.md) `Azera\Queue\JobInterface`
- [QueueException](Queue_QueueException.md) `Azera\Queue\QueueException`
- [QueueInterface](Queue_QueueInterface.md) `Azera\Queue\QueueInterface`
- [SyncQueue](Queue_SyncQueue.md) `Azera\Queue\SyncQueue`

### `Azera\Security`

- [AuthManagerInterface](Security_AuthManagerInterface.md) `Azera\Security\AuthManagerInterface`
- [CsrfMiddleware](Security_CsrfMiddleware.md) `Azera\Security\CsrfMiddleware`
- [GuardInterface](Security_GuardInterface.md) `Azera\Security\GuardInterface`
- [Hasher](Security_Hasher.md) `Azera\Security\Hasher`
- [RateLimiter](Security_RateLimiter.md) `Azera\Security\RateLimiter`

### `Azera\Sync`

- [CodeGenerator](Sync_CodeGenerator.md) `Azera\Sync\CodeGenerator`
- [ModelDiff](Sync_ModelDiff.md) `Azera\Sync\ModelDiff`
- [DiffOperation](Sync_DiffOperation.md) `Azera\Sync\DiffOperation`
- [AddProperty](Sync_AddProperty.md) `Azera\Sync\AddProperty`
- [RemoveProperty](Sync_RemoveProperty.md) `Azera\Sync\RemoveProperty`
- [AddAccessor](Sync_AddAccessor.md) `Azera\Sync\AddAccessor`
- [UpdatePropertyType](Sync_UpdatePropertyType.md) `Azera\Sync\UpdatePropertyType`
- [UpdatePropertyComment](Sync_UpdatePropertyComment.md) `Azera\Sync\UpdatePropertyComment`
- [UpdateClassComment](Sync_UpdateClassComment.md) `Azera\Sync\UpdateClassComment`
- [ModelParser](Sync_ModelParser.md) `Azera\Sync\ModelParser`
- [ParsedModel](Sync_ParsedModel.md) `Azera\Sync\ParsedModel`
- [ParsedProperty](Sync_ParsedProperty.md) `Azera\Sync\ParsedProperty`
- [SchemaDiff](Sync_SchemaDiff.md) `Azera\Sync\SchemaDiff`
- [SqlOperation](Sync_SqlOperation.md) `Azera\Sync\SqlOperation`
- [CreateTable](Sync_CreateTable.md) `Azera\Sync\CreateTable`
- [AddColumn](Sync_AddColumn.md) `Azera\Sync\AddColumn`
- [DropColumn](Sync_DropColumn.md) `Azera\Sync\DropColumn`
- [AlterColumn](Sync_AlterColumn.md) `Azera\Sync\AlterColumn`
- [DropIndex](Sync_DropIndex.md) `Azera\Sync\DropIndex`
- [SqlGenerator](Sync_SqlGenerator.md) `Azera\Sync\SqlGenerator`
- [SyncOptions](Sync_SyncOptions.md) `Azera\Sync\SyncOptions`
- [SyncResult](Sync_SyncResult.md) `Azera\Sync\SyncResult`
- [SyncRunner](Sync_SyncRunner.md) `Azera\Sync\SyncRunner`

### `Azera\Sync\Schema`

- [MySqlSchemaProvider](Sync_Schema_MySqlSchemaProvider.md) `Azera\Sync\Schema\MySqlSchemaProvider`
- [PostgresSchemaProvider](Sync_Schema_PostgresSchemaProvider.md) `Azera\Sync\Schema\PostgresSchemaProvider`
- [SchemaProvider](Sync_Schema_SchemaProvider.md) `Azera\Sync\Schema\SchemaProvider`
- [TableSchema](Sync_Schema_TableSchema.md) `Azera\Sync\Schema\TableSchema`
- [ColumnSchema](Sync_Schema_ColumnSchema.md) `Azera\Sync\Schema\ColumnSchema`
- [IndexSchema](Sync_Schema_IndexSchema.md) `Azera\Sync\Schema\IndexSchema`
- [SchemaProviderFactory](Sync_Schema_SchemaProviderFactory.md) `Azera\Sync\Schema\SchemaProviderFactory`
- [SqliteSchemaProvider](Sync_Schema_SqliteSchemaProvider.md) `Azera\Sync\Schema\SqliteSchemaProvider`

### `Azera\Validation`

- [FieldValidator](Validation_FieldValidator.md) `Azera\Validation\FieldValidator`
- [ValidationException](Validation_ValidationException.md) `Azera\Validation\ValidationException`
- [Validator](Validation_Validator.md) `Azera\Validation\Validator`

