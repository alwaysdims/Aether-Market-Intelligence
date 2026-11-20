"""
Script Diagnosis Deskriptif EXP-01 Tanpa Training Ulang
Menganalisis file dataset dan file prediksi test existing:
- reports/swing_ohlcv_dataset_features.csv
- reports/artifacts_exp01/test_predictions_exp01.csv
"""

import pandas as pd
import numpy as np

def get_tick_size(price):
    if price < 200:
        return 1.0
    elif price < 500:
        return 2.0
    elif price < 2000:
        return 5.0
    elif price < 5000:
        return 10.0
    else:
        return 25.0

def calc_simulated_returns(df_slice):
    entry_p = df_slice['t_plus_1_open'].values
    exit_p = df_slice['t_plus_5_close'].values
    entry_ticks = np.array([get_tick_size(p) for p in entry_p])
    exit_ticks = np.array([get_tick_size(p) for p in exit_p])
    fill_entry = entry_p + entry_ticks
    fill_exit = exit_p - exit_ticks
    cost_entry = fill_entry * (1.0 + 0.0015)
    net_proceeds = fill_exit * (1.0 - 0.0025)
    net_ret = (net_proceeds - cost_entry) / cost_entry
    gross_ret = (exit_p - entry_p) / entry_p
    slippage_cost = (fill_entry - entry_p)/entry_p + (exit_p - fill_exit)/entry_p
    fee_cost = np.full(len(df_slice), 0.0040)
    return pd.DataFrame({
        'gross_ret': gross_ret,
        'net_ret': net_ret,
        'slippage_cost': slippage_cost,
        'fee_cost': fee_cost,
        'win': net_ret > 0
    }, index=df_slice.index)

# Load data
df = pd.read_csv('reports/swing_ohlcv_dataset_features.csv')
test_df = df[df['split'] == 'TEST'].copy()
preds = pd.read_csv('reports/artifacts_exp01/test_predictions_exp01.csv')

test_df['prob_up'] = preds['prob_up'].values
test_df['prob_down'] = preds['prob_down'].values
test_df['pred_class'] = preds['pred_class'].values

# Hitung simulasi untuk SELURUH bar test
all_sim = calc_simulated_returns(test_df)
test_df['gross_ret'] = all_sim['gross_ret'].values
test_df['net_ret'] = all_sim['net_ret'].values
test_df['slippage_cost'] = all_sim['slippage_cost'].values
test_df['fee_cost'] = all_sim['fee_cost'].values
test_df['win'] = all_sim['win'].values

# Sinyal
test_df['signal_model_up'] = test_df['prob_up'] >= 0.38
test_df['signal_momentum_up'] = test_df['close_to_sma20'] > test_df['close_to_sma50']

# 1. Bandingkan: Model UP vs Universe Eligible vs Naive Momentum
print("=== 1. PERBANDINGAN RATA-RATA RETURN PER SKENARIO ===")
for name, mask in [
    ("Universe Eligible (All Test Bars)", np.ones(len(test_df), dtype=bool)),
    ("Naive Momentum Baseline (SMA20 > SMA50)", test_df['signal_momentum_up']),
    ("Model Candidate UP (prob_up >= 0.38)", test_df['signal_model_up']),
]:
    sub = test_df[mask]
    n = len(sub)
    mean_gross = sub['gross_ret'].mean() * 100
    mean_net = sub['net_ret'].mean() * 100
    win_rate = sub['win'].mean() * 100
    mean_slip = sub['slippage_cost'].mean() * 100
    print(f"{name}: N={n}, WinRate={win_rate:.2f}%, Gross={mean_gross:+.2f}%, Net={mean_net:+.2f}%, AvgSlippage={mean_slip:.2f}%")

# 2. Breakdown Model UP: Bulan, Likuiditas, Rentang Harga, Probabilitas
print("\n=== 2. BREAKDOWN SKENARIO MODEL UP ===")
model_up = test_df[test_df['signal_model_up']].copy()

# A. Per Bulan
model_up['month'] = model_up['bar_date'].str.slice(0, 7)
print("--- A. Per Bulan ---")
for m, g in model_up.groupby('month'):
    print(f"  {m}: N={len(g)}, WinRate={g['win'].mean()*100:.1f}%, Gross={g['gross_ret'].mean()*100:+.2f}%, Net={g['net_ret'].mean()*100:+.2f}%")

# B. Per Kelompok Likuiditas (Turnover Quintile)
test_df['turnover_quintile'] = pd.qcut(test_df['turnover_idr'], 5, labels=['Q1 (Terendah)', 'Q2', 'Q3', 'Q4', 'Q5 (Tertinggi)'])
model_up['turnover_quintile'] = test_df.loc[model_up.index, 'turnover_quintile']
print("\n--- B. Per Kelompok Likuiditas (Turnover Quintiles) ---")
for q, g in model_up.groupby('turnover_quintile', observed=False):
    print(f"  {q}: N={len(g)}, WinRate={g['win'].mean()*100:.1f}%, Gross={g['gross_ret'].mean()*100:+.2f}%, Net={g['net_ret'].mean()*100:+.2f}%, AvgSlip={g['slippage_cost'].mean()*100:.2f}%")

# C. Per Rentang Harga (Tick Size Brackets)
def price_bucket(p):
    if p < 200:
        return "< 200 (Tick 1, Impact >= 1%)"
    elif p < 500:
        return "200-499 (Tick 2, Impact ~0.8-1%)"
    elif p < 2000:
        return "500-1999 (Tick 5, Impact ~0.5-1%)"
    elif p < 5000:
        return "2000-4999 (Tick 10, Impact ~0.4-0.5%)"
    else:
        return ">= 5000 (Tick 25, Impact <= 0.5%)"

model_up['price_bucket'] = model_up['t_plus_1_open'].apply(price_bucket)
print("\n--- C. Per Rentang Harga ---")
for pb, g in model_up.groupby('price_bucket'):
    print(f"  {pb}: N={len(g)}, WinRate={g['win'].mean()*100:.1f}%, Gross={g['gross_ret'].mean()*100:+.2f}%, Net={g['net_ret'].mean()*100:+.2f}%, AvgSlip={g['slippage_cost'].mean()*100:.2f}%")

# D. Per Bin Probabilitas UP
prob_bins = [0.38, 0.42, 0.46, 0.50, 1.0]
model_up['prob_bin'] = pd.cut(model_up['prob_up'], bins=prob_bins, right=False)
print("\n--- D. Per Bin Probabilitas UP ---")
for pb, g in model_up.groupby('prob_bin', observed=False):
    print(f"  {pb}: N={len(g)}, WinRate={g['win'].mean()*100:.1f}%, Gross={g['gross_ret'].mean()*100:+.2f}%, Net={g['net_ret'].mean()*100:+.2f}%")

# 3. Analisis Keselarasan Label Close-to-Close vs Transaksi Open t+1
print("\n=== 3. KESELARASAN LABEL CLOSE-TO-CLOSE VS TRANSAKSI OPEN T+1 ===")
# Overnight gap = (Open_{t+1} - Close_t) / Close_t
model_up['overnight_gap'] = (model_up['t_plus_1_open'] - model_up['close']) / model_up['close']
print(f"Rata-rata Overnight Gap (Open t+1 vs Close t): {model_up['overnight_gap'].mean()*100:+.2f}%")

# Cek korelasi return close-to-close (ret_5d_forward) vs gross_ret (open t+1 to close t+5)
corr = model_up['ret_5d_forward'].corr(model_up['gross_ret'])
print(f"Korelasi Label Close-to-Close vs Return Transaksi Open-to-Close: {corr:.4f}")

# Berapa sampel yang labelnya UP (+1) tetapi gross transaction return NEGATIF?
label_up_mask = model_up['ret_5d_forward'] > 0.015
gross_neg_mask = model_up['gross_ret'] < 0
contradiction = label_up_mask & gross_neg_mask
print(f"Label UP (>+1.5%) tetapi Gross Return Transaksi Negatif: {contradiction.sum()} / {label_up_mask.sum()} ({contradiction.sum()/label_up_mask.sum()*100:.2f}%)")

# Rata-rata slippage vs fee
print(f"\nRata-rata Komponen Biaya Skenario Model UP:")
print(f"  Slippage (2 ticks): {model_up['slippage_cost'].mean()*100:.2f}%")
print(f"  Fee Transaksi:      {model_up['fee_cost'].mean()*100:.2f}%")
print(f"  Total Drag Friksi:  {(model_up['slippage_cost'].mean() + model_up['fee_cost'].mean())*100:.2f}%")
