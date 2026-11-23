<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\Databases\AddDatabaseNodeTool;
use App\Mcp\Tools\Databases\AddExtensionPackageTool;
use App\Mcp\Tools\Databases\AddTrustedSourceTool;
use App\Mcp\Tools\Databases\CancelMigrationTool;
use App\Mcp\Tools\Databases\CheckDatabaseUpgradeTool;
use App\Mcp\Tools\Databases\CheckExtensionPackageVersionTool;
use App\Mcp\Tools\Databases\CheckMigrationTool;
use App\Mcp\Tools\Databases\CheckRecoveryTool;
use App\Mcp\Tools\Databases\CreateConnectionPoolTool;
use App\Mcp\Tools\Databases\CreateDatabaseTool;
use App\Mcp\Tools\Databases\CreateDatabaseUserTool;
use App\Mcp\Tools\Databases\CreateSchemaTool;
use App\Mcp\Tools\Databases\DeleteConnectionPoolTool;
use App\Mcp\Tools\Databases\DeleteDatabaseNodeTool;
use App\Mcp\Tools\Databases\DeleteDatabaseTool;
use App\Mcp\Tools\Databases\DeleteDatabaseUserTool;
use App\Mcp\Tools\Databases\DeleteSchemaTool;
use App\Mcp\Tools\Databases\DeleteTrustedSourceTool;
use App\Mcp\Tools\Databases\DisableDatabaseExtensionTool;
use App\Mcp\Tools\Databases\DisableRecoveryTool;
use App\Mcp\Tools\Databases\EnableDatabaseExtensionTool;
use App\Mcp\Tools\Databases\EnableRecoveryTool;
use App\Mcp\Tools\Databases\FailoverDatabaseTool;
use App\Mcp\Tools\Databases\FinishMigrationTool;
use App\Mcp\Tools\Databases\GetDatabaseLogTool;
use App\Mcp\Tools\Databases\GetDatabaseMetricsTool;
use App\Mcp\Tools\Databases\GetDatabaseNodeTool;
use App\Mcp\Tools\Databases\GetDatabaseTool;
use App\Mcp\Tools\Databases\GetDatabaseUserCredentialsTool;
use App\Mcp\Tools\Databases\GetNodeLogsTool;
use App\Mcp\Tools\Databases\GetRecoveryTool;
use App\Mcp\Tools\Databases\ListConnectionPoolsTool;
use App\Mcp\Tools\Databases\ListDatabaseEnginesTool;
use App\Mcp\Tools\Databases\ListDatabaseExtensionsTool;
use App\Mcp\Tools\Databases\ListDatabaseLogsTool;
use App\Mcp\Tools\Databases\ListDatabaseNodesTool;
use App\Mcp\Tools\Databases\ListDatabasesTool;
use App\Mcp\Tools\Databases\ListDatabaseUsersTool;
use App\Mcp\Tools\Databases\ListExtensionPackagesTool;
use App\Mcp\Tools\Databases\ListMigrationsTool;
use App\Mcp\Tools\Databases\ListSchemasTool;
use App\Mcp\Tools\Databases\ListTrustedSourcesTool;
use App\Mcp\Tools\Databases\RebootDatabaseNodeTool;
use App\Mcp\Tools\Databases\RebootDatabaseTool;
use App\Mcp\Tools\Databases\RecoverDatabaseTool;
use App\Mcp\Tools\Databases\RefreshDatabaseTool;
use App\Mcp\Tools\Databases\RemoveExtensionPackageTool;
use App\Mcp\Tools\Databases\ResetDatabaseUserPasswordTool;
use App\Mcp\Tools\Databases\RestoreDatabaseTool;
use App\Mcp\Tools\Databases\RetryDatabaseSetupTool;
use App\Mcp\Tools\Databases\RetuneDatabaseNodeTool;
use App\Mcp\Tools\Databases\RetuneDatabaseTool;
use App\Mcp\Tools\Databases\RunBackupTool;
use App\Mcp\Tools\Databases\StartMigrationTool;
use App\Mcp\Tools\Databases\StopDatabaseOperationTool;
use App\Mcp\Tools\Databases\StopNodeOperationTool;
use App\Mcp\Tools\Databases\UpdateDatabaseConfigTool;
use App\Mcp\Tools\Databases\UpdateDatabaseDomainTool;
use App\Mcp\Tools\Databases\UpdateDatabaseExtensionTool;
use App\Mcp\Tools\Databases\UpdateDatabaseNodeTool;
use App\Mcp\Tools\Databases\UpdateExtensionPackageTool;
use App\Mcp\Tools\Databases\UpdateMaintenanceWindowTool;
use App\Mcp\Tools\Databases\UpdateNodeConfigTool;
use App\Mcp\Tools\Databases\UpdatePersistenceTool;
use App\Mcp\Tools\Databases\UpdateRecoveryScheduleTool;
use App\Mcp\Tools\Databases\UpdateUserPasswordEncryptionTool;
use App\Mcp\Tools\Databases\UpgradeDatabaseTool;
use App\Mcp\Tools\DnsProviders\CreateDnsProviderTool;
use App\Mcp\Tools\DnsProviders\DeleteDnsProviderTool;
use App\Mcp\Tools\DnsProviders\GetDnsProviderTool;
use App\Mcp\Tools\DnsProviders\ListDnsProvidersTool;
use App\Mcp\Tools\DnsProviders\ListDnsProviderTypesTool;
use App\Mcp\Tools\DnsProviders\RefreshDnsProviderTool;
use App\Mcp\Tools\DnsProviders\UpdateDnsProviderTool;
use App\Mcp\Tools\Projects\CreateProjectTool;
use App\Mcp\Tools\Projects\DeleteProjectTool;
use App\Mcp\Tools\Projects\ListProjectsTool;
use App\Mcp\Tools\Projects\MoveToProjectTool;
use App\Mcp\Tools\Projects\UpdateProjectTool;
use App\Mcp\Tools\ServerProviders\CreateServerProviderTool;
use App\Mcp\Tools\ServerProviders\DeleteNetworkTool;
use App\Mcp\Tools\ServerProviders\DeleteServerProviderTool;
use App\Mcp\Tools\ServerProviders\GetServerProviderTool;
use App\Mcp\Tools\ServerProviders\ListNetworksTool;
use App\Mcp\Tools\ServerProviders\ListProviderNetworksTool;
use App\Mcp\Tools\ServerProviders\ListProviderPlansTool;
use App\Mcp\Tools\ServerProviders\ListProviderRegionsTool;
use App\Mcp\Tools\ServerProviders\ListServerProvidersTool;
use App\Mcp\Tools\ServerProviders\ListServerProviderTypesTool;
use App\Mcp\Tools\ServerProviders\OrderServerTool;
use App\Mcp\Tools\ServerProviders\UpdateServerProviderTool;
use App\Mcp\Tools\Servers\CreateServerTool;
use App\Mcp\Tools\Servers\CreateServerUserTool;
use App\Mcp\Tools\Servers\DeleteServerTool;
use App\Mcp\Tools\Servers\DeleteServerUserTool;
use App\Mcp\Tools\Servers\GetServerLogTool;
use App\Mcp\Tools\Servers\GetServerSshKeyTool;
use App\Mcp\Tools\Servers\GetServerTool;
use App\Mcp\Tools\Servers\ListServerLogsTool;
use App\Mcp\Tools\Servers\ListServersTool;
use App\Mcp\Tools\Servers\ListServerUsersTool;
use App\Mcp\Tools\Servers\ProvisionServerTool;
use App\Mcp\Tools\Servers\RefreshServerTool;
use App\Mcp\Tools\Servers\RunServerOperationTool;
use App\Mcp\Tools\Servers\UpdateServerNetworkTool;
use App\Mcp\Tools\StorageProviders\CreateStorageProviderTool;
use App\Mcp\Tools\StorageProviders\DeleteStorageProviderTool;
use App\Mcp\Tools\StorageProviders\GetStorageProviderTool;
use App\Mcp\Tools\StorageProviders\ListStorageProvidersTool;
use App\Mcp\Tools\StorageProviders\TestStorageProviderTool;
use App\Mcp\Tools\StorageProviders\UpdateStorageProviderTool;
use App\Mcp\Tools\System\GetSystemTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tools\ToolSearch;

#[Name('Databasify')]
#[Version('1.0.0')]
#[Instructions('Manage Databasify projects, providers, servers and databases. Ids are UUIDs: list or search first to find them. Operations such as creating, failing over, rebooting, upgrading or restoring run in the background: the tool returns at once, then poll the matching get tool until the status settles. Use search_tools to find the tool for anything not listed directly.')]
class DatabasifyServer extends Server
{
    protected array $tools = [
        GetSystemTool::class,
        ListProjectsTool::class,
        ListServersTool::class,
        ListDatabasesTool::class,
        GetDatabaseTool::class,
        ToolSearch::class => [
            AddDatabaseNodeTool::class,
            AddExtensionPackageTool::class,
            AddTrustedSourceTool::class,
            CancelMigrationTool::class,
            CheckDatabaseUpgradeTool::class,
            CheckExtensionPackageVersionTool::class,
            CheckMigrationTool::class,
            CheckRecoveryTool::class,
            CreateConnectionPoolTool::class,
            CreateDatabaseTool::class,
            CreateDatabaseUserTool::class,
            CreateSchemaTool::class,
            DeleteConnectionPoolTool::class,
            DeleteDatabaseNodeTool::class,
            DeleteDatabaseTool::class,
            DeleteDatabaseUserTool::class,
            DeleteSchemaTool::class,
            DeleteTrustedSourceTool::class,
            DisableDatabaseExtensionTool::class,
            DisableRecoveryTool::class,
            EnableDatabaseExtensionTool::class,
            EnableRecoveryTool::class,
            FailoverDatabaseTool::class,
            FinishMigrationTool::class,
            GetDatabaseLogTool::class,
            GetDatabaseMetricsTool::class,
            GetDatabaseNodeTool::class,
            GetDatabaseUserCredentialsTool::class,
            GetNodeLogsTool::class,
            GetRecoveryTool::class,
            ListConnectionPoolsTool::class,
            ListDatabaseEnginesTool::class,
            ListDatabaseExtensionsTool::class,
            ListDatabaseLogsTool::class,
            ListDatabaseNodesTool::class,
            ListDatabaseUsersTool::class,
            ListExtensionPackagesTool::class,
            ListMigrationsTool::class,
            ListSchemasTool::class,
            ListTrustedSourcesTool::class,
            RebootDatabaseNodeTool::class,
            RebootDatabaseTool::class,
            RecoverDatabaseTool::class,
            RemoveExtensionPackageTool::class,
            RefreshDatabaseTool::class,
            ResetDatabaseUserPasswordTool::class,
            RestoreDatabaseTool::class,
            RetryDatabaseSetupTool::class,
            RetuneDatabaseNodeTool::class,
            RetuneDatabaseTool::class,
            RunBackupTool::class,
            StartMigrationTool::class,
            StopDatabaseOperationTool::class,
            StopNodeOperationTool::class,
            UpdateDatabaseConfigTool::class,
            UpdateExtensionPackageTool::class,
            UpdateDatabaseExtensionTool::class,
            UpdateDatabaseDomainTool::class,
            UpdateDatabaseNodeTool::class,
            UpdateMaintenanceWindowTool::class,
            UpdateNodeConfigTool::class,
            UpdatePersistenceTool::class,
            UpdateRecoveryScheduleTool::class,
            UpdateUserPasswordEncryptionTool::class,
            UpgradeDatabaseTool::class,
            CreateDnsProviderTool::class,
            DeleteDnsProviderTool::class,
            GetDnsProviderTool::class,
            ListDnsProviderTypesTool::class,
            ListDnsProvidersTool::class,
            RefreshDnsProviderTool::class,
            UpdateDnsProviderTool::class,
            CreateProjectTool::class,
            DeleteProjectTool::class,
            MoveToProjectTool::class,
            UpdateProjectTool::class,
            CreateServerProviderTool::class,
            DeleteNetworkTool::class,
            DeleteServerProviderTool::class,
            GetServerProviderTool::class,
            ListNetworksTool::class,
            ListProviderNetworksTool::class,
            ListProviderPlansTool::class,
            ListProviderRegionsTool::class,
            ListServerProviderTypesTool::class,
            ListServerProvidersTool::class,
            OrderServerTool::class,
            UpdateServerProviderTool::class,
            CreateServerTool::class,
            CreateServerUserTool::class,
            DeleteServerTool::class,
            DeleteServerUserTool::class,
            GetServerLogTool::class,
            GetServerSshKeyTool::class,
            GetServerTool::class,
            ListServerLogsTool::class,
            ListServerUsersTool::class,
            ProvisionServerTool::class,
            RefreshServerTool::class,
            RunServerOperationTool::class,
            UpdateServerNetworkTool::class,
            CreateStorageProviderTool::class,
            DeleteStorageProviderTool::class,
            GetStorageProviderTool::class,
            ListStorageProvidersTool::class,
            TestStorageProviderTool::class,
            UpdateStorageProviderTool::class,
        ],
    ];
}
