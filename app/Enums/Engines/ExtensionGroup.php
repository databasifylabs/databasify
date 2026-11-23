<?php

declare(strict_types=1);

namespace App\Enums\Engines;

enum ExtensionGroup: string
{
    case BuiltIn = 'built_in';
    case Geo = 'geo';
    case Search = 'search';
    case TimeSeries = 'time_series';
    case DataTypes = 'data_types';
    case Graph = 'graph';
    case ForeignData = 'foreign_data';
    case Compatibility = 'compatibility';
    case Operations = 'operations';
    case Observability = 'observability';
    case Audit = 'audit';
    case Development = 'development';
    case Languages = 'languages';

    public function label(): string
    {
        return match ($this) {
            self::BuiltIn => 'Built into the engine',
            self::Geo => 'Geo',
            self::Search => 'Vectors and search',
            self::TimeSeries => 'Time series, scheduling and partitions',
            self::DataTypes => 'Data types and functions',
            self::Graph => 'Graph',
            self::ForeignData => 'Foreign data',
            self::Compatibility => 'Compatibility',
            self::Operations => 'Operations and tuning',
            self::Observability => 'Observability',
            self::Audit => 'Audit',
            self::Development => 'Development and testing',
            self::Languages => 'Languages',
        };
    }
}
