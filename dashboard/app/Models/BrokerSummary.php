<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BrokerSummary extends Model
{
    use HasFactory;

    protected $table = 'broker_summaries';

    protected $fillable = [
        'ticker',
        'summary_date',
        'broker_code',
        'broker_name',
        'board',
        'buy_volume',
        'buy_value',
        'buy_frequency',
        'buy_avg_price',
        'sell_volume',
        'sell_value',
        'sell_frequency',
        'sell_avg_price',
        'net_volume',
        'net_value',
        'flow_type',
        'unit',
        'currency',
        'source',
        'data_quality',
        'fetched_at',
        'raw_payload',
        'data_hash',
    ];

    protected $casts = [
        'summary_date' => 'date',
        'buy_volume' => 'integer',
        'buy_value' => 'float',
        'buy_frequency' => 'integer',
        'buy_avg_price' => 'float',
        'sell_volume' => 'integer',
        'sell_value' => 'float',
        'sell_frequency' => 'integer',
        'sell_avg_price' => 'float',
        'net_volume' => 'integer',
        'net_value' => 'float',
        'fetched_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    /**
     * Scope for top net buyers.
     */
    public function scopeTopBuyers($query, int $limit = 5)
    {
        return $query->where('net_value', '>', 0)->orderByDesc('net_value')->limit($limit);
    }

    /**
     * Scope for top net sellers.
     */
    public function scopeTopSellers($query, int $limit = 5)
    {
        return $query->where('net_value', '<', 0)->orderBy('net_value')->limit($limit);
    }
}
