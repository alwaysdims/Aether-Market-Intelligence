# KONTRAK DATA DAN PAYLOAD INTEGRASI

## 1. Kontrak Output Adapter Hermes (`services/hermes_adapter.py`)
Setiap data yang diekstraksi dari Hermes Stock Agent dikonversi menjadi format JSON standar:

```json
{
  "source": "hermes-stock-agent",
  "generated_at_wib": "2026-10-16 16:05:00",
  "market_regime": {
    "ihsg_status": "bullish_continuation",
    "market_phase": "accumulation"
  },
  "snapshots": [
    {
      "ticker": "BBCA",
      "bar_date": "2026-10-16",
      "source_timestamp": "2026-10-16T16:00:00+07:00",
      "open": 10250.0,
      "high": 10400.0,
      "low": 10200.0,
      "close": 10350.0,
      "volume": 85400100,
      "data_classification": "end_of_day",
      "indicators": {
        "atr14": 150.0,
        "rvol20": 1.45,
        "close_position": 0.75
      }
    }
  ],
  "paper_trading_summary": {
    "exp01": {
      "equity": 500000.0,
      "cash": 500000.0,
      "open_positions": 0
    },
    "exp04": {
      "equity": 500000.0,
      "cash": 500000.0,
      "open_positions": 0
    }
  }
}
```

## 2. Kontrak Input/Output AI Model (`ag/gemini-3.8-flash-high`)
Payload inferensi AI wajib divalidasi ke format JSON berikut:

```json
{
  "market_regime_summary": "Pasar bergerak variatif dengan indikasi akumulasi selektif pada sektor perbankan dan energi.",
  "impact_direction": "positive",
  "impact_horizon": "multi_day",
  "confidence_score": 0.82,
  "impacted_tickers": [
    {
      "ticker": "MEDC",
      "rationale": "Kenaikan harga minyak acuan dan peningkatan volume harian.",
      "support": 1485,
      "resistance": 1630
    }
  ],
  "risks": [
    "Volatilitas nilai tukar rupiah terhadap USD",
    "Rotasi likuiditas intraday"
  ],
  "unknowns": [
    "Arah rilis suku bunga The Fed pekan depan"
  ]
}
```
