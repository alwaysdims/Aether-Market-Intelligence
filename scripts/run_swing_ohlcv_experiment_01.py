"""
Eksperimen Offline Swing OHLCV Pertama (Aether Market Intelligence)
Isolasi Penuh: Training hanya pada TRAIN. Tuning hiperparameter, kalibrasi,
dan threshold abstain hanya pada VALIDATION. TEST hanya dievaluasi SATU KALI.
Tidak mengubah model atau threshold produksi.
"""

import sys
import os
import hashlib
import json
import time
import numpy as np
import pandas as pd
from sklearn.preprocessing import StandardScaler
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (
    confusion_matrix,
    balanced_accuracy_score,
    f1_score,
    roc_auc_score,
    classification_report
)

def get_file_sha256(filepath):
    h = hashlib.sha256()
    with open(filepath, 'rb') as f:
        while chunk := f.read(8192 * 1024):
            h.update(chunk)
    return h.hexdigest()

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

def main():
    start_time = time.time()
    csv_path = 'reports/swing_ohlcv_dataset_features.csv'
    if not os.path.exists(csv_path):
        print(f"Dataset CSV not found at {csv_path}")
        sys.exit(1)

    dataset_hash = get_file_sha256(csv_path)
    print(f"[1] Dataset Hash SHA-256: {dataset_hash}")

    df = pd.read_csv(csv_path)
    print(f"[2] Loaded Dataset: {len(df)} rows across {df['ticker'].nunique()} tickers.")
    print("Split counts:\n", df['split'].value_counts())

    # Verifikasi Integritas Dataset
    # 1. Cek duplikasi (ticker, bar_date)
    dup_count = df.duplicated(subset=['ticker', 'bar_date']).sum()
    if dup_count > 0:
        print(f"ERROR: Found {dup_count} duplicate samples!")
        sys.exit(1)
    print("[3] Integrity Verified: ZERO duplicates per (ticker, bar_date).")

    # 2. Cek fitur finite
    feature_cols = [
        'ret_1d', 'ret_5d', 'ret_20d',
        'close_to_sma20', 'close_to_sma50',
        'atr14_norm', 'hl_spread_norm',
        'volume_to_sma20', 'log_turnover'
    ]
    assert np.all(np.isfinite(df[feature_cols].values)), "Found non-finite features!"
    print(f"[4] Integrity Verified: All {len(feature_cols)} features are 100% finite.")

    # 3. Label mapping: UP: 1, DOWN: 2, NEUTRAL: 0
    # Menggunakan integer mapping kanonikal
    label_map = {'NEUTRAL': 0, 'UP': 1, 'DOWN': 2}
    inv_label_map = {0: 'NEUTRAL', 1: 'UP', 2: 'DOWN'}
    df['label_idx'] = df['label_class'].map(label_map)

    # Pisahkan split
    train_df = df[df['split'] == 'TRAIN'].copy()
    val_df = df[df['split'] == 'VALIDATION'].copy()
    test_df = df[df['split'] == 'TEST'].copy()

    print(f"[5] Split rows -> Train: {len(train_df)}, Val: {len(val_df)}, Test: {len(test_df)}")

    # Preprocessing strictly FIT on TRAIN only
    scaler = StandardScaler()
    X_train = scaler.fit_transform(train_df[feature_cols].values)
    y_train = train_df['label_idx'].values

    X_val = scaler.transform(val_df[feature_cols].values)
    y_val = val_df['label_idx'].values

    X_test = scaler.transform(test_df[feature_cols].values)
    y_test = test_df['label_idx'].values

    # Model Search Space on Validation Only
    # Menguji kandidat Logistic Regression multi-class multinomial dengan C in [0.01, 0.1, 1.0], class_weight in [None, 'balanced']
    print("\n[6] Hyperparameter Search on VALIDATION ONLY (Budget: 6 fits)...")
    candidates = [
        {'C': 0.01, 'class_weight': None},
        {'C': 0.1, 'class_weight': None},
        {'C': 1.0, 'class_weight': None},
        {'C': 0.01, 'class_weight': 'balanced'},
        {'C': 0.1, 'class_weight': 'balanced'},
        {'C': 1.0, 'class_weight': 'balanced'},
    ]

    best_val_score = -1.0
    best_config = None
    best_model = None

    for cfg in candidates:
        clf = LogisticRegression(
            C=cfg['C'],
            class_weight=cfg['class_weight'],
            solver='lbfgs',
            max_iter=500,
            random_state=42
        )
        clf.fit(X_train, y_train)
        val_preds = clf.predict(X_val)
        val_probs = clf.predict_proba(X_val)
        
        # Macro ROC-AUC OvR on Val
        val_auc = roc_auc_score(y_val, val_probs, multi_class='ovr', average='macro')
        val_bal_acc = balanced_accuracy_score(y_val, val_preds)
        val_f1 = f1_score(y_val, val_preds, average='macro')
        
        score = (val_auc + val_bal_acc) / 2.0
        print(f"  Config C={cfg['C']}, weight={cfg['class_weight']} -> BalAcc={val_bal_acc:.4f}, AUC={val_auc:.4f}, F1={val_f1:.4f} (Score={score:.4f})")
        
        if score > best_val_score:
            best_val_score = score
            best_config = cfg
            best_model = clf

    print(f"\n[7] BEST CONFIGURATION FROZEN FROM VALIDATION: {best_config}")
    print(f"    Val Balanced Accuracy: {balanced_accuracy_score(y_val, best_model.predict(X_val)):.4f}")
    print(f"    Val Macro ROC-AUC: {roc_auc_score(y_val, best_model.predict_proba(X_val), multi_class='ovr', average='macro'):.4f}")

    # Eksplorasi threshold abstain tau pada VALIDATION ONLY
    val_probs = best_model.predict_proba(X_val)
    # Probabilitas kelas UP (idx 1)
    val_p_up = val_probs[:, 1]
    
    # Uji beberapa ambang batas tau_up pada val
    tau_candidates = [0.33, 0.38, 0.42, 0.45, 0.50]
    best_tau = 0.33
    best_val_up_prec = 0.0
    
    print("\n[8] Abstain Threshold (tau) Tuning on VALIDATION for UP Decisions:")
    for tau in tau_candidates:
        mask = (val_p_up >= tau)
        cov = mask.mean()
        if mask.sum() > 0:
            # Presisi: berapa persen yang benar-benar label UP (1)
            prec = (y_val[mask] == 1).mean()
        else:
            prec = 0.0
        print(f"  tau={tau:.2f} -> Coverage={cov*100:.2f}%, Precision(UP)={prec*100:.2f}% (N={mask.sum()})")
        if prec > best_val_up_prec and mask.sum() >= 1000:
            best_val_up_prec = prec
            best_tau = tau

    print(f"    Chosen tau_up on Validation: {best_tau}")

    # =========================================================================
    # SATU KALI EVALUASI TEST SET (FROZEN EVALUATION - STRICTLY ONCE)
    # =========================================================================
    print("\n" + "="*70)
    print("[9] STRICTLY ONCE EVALUATION ON UNSEEN TEST SET")
    print("="*70)

    test_preds = best_model.predict(X_test)
    test_probs = best_model.predict_proba(X_test)

    # 1. Metrik Klasifikasi Model
    test_bal_acc = balanced_accuracy_score(y_test, test_preds)
    test_macro_f1 = f1_score(y_test, test_preds, average='macro')
    test_roc_auc = roc_auc_score(y_test, test_probs, multi_class='ovr', average='macro')
    
    cm = confusion_matrix(y_test, test_preds, labels=[0, 1, 2])
    # Labels: 0=NEUTRAL, 1=UP, 2=DOWN

    # 2. Baseline Pembanding pada Test
    # Majority Class Baseline (ditentukan dari TRAIN: class DOWN = 2)
    maj_class = int(train_df['label_idx'].mode()[0])
    maj_preds = np.full_like(y_test, maj_class)
    maj_bal_acc = balanced_accuracy_score(y_test, maj_preds)
    maj_macro_f1 = f1_score(y_test, maj_preds, average='macro')

    # Naive Momentum Baseline pada Test (SMA20 > SMA50 -> UP, SMA20 < SMA50 -> DOWN)
    # close_to_sma20 vs close_to_sma50
    mom_preds = np.where(test_df['close_to_sma20'].values > test_df['close_to_sma50'].values, 1, 2)
    mom_bal_acc = balanced_accuracy_score(y_test, mom_preds)
    mom_macro_f1 = f1_score(y_test, mom_preds, average='macro')

    print(f"Model Balanced Accuracy OOS: {test_bal_acc:.4f} (Baseline Majority: {maj_bal_acc:.4f}, Baseline Momentum: {mom_bal_acc:.4f})")
    print(f"Model Macro F1 OOS:          {test_macro_f1:.4f} (Baseline Majority: {maj_macro_f1:.4f}, Baseline Momentum: {mom_macro_f1:.4f})")
    print(f"Model OvR Macro ROC-AUC OOS: {test_roc_auc:.4f}")
    print("\nConfusion Matrix (Rows=True, Cols=Pred) [0=NEUTRAL, 1=UP, 2=DOWN]:")
    print(cm)
    print("\nClassification Report:")
    print(classification_report(y_test, test_preds, target_names=['NEUTRAL', 'UP', 'DOWN'], digits=4))

    # 3. Selective Decision on Test (tau_up)
    test_p_up = test_probs[:, 1]
    test_p_down = test_probs[:, 2]

    up_mask = (test_p_up >= best_tau)
    up_coverage = up_mask.mean()
    up_precision = (y_test[up_mask] == 1).mean() if up_mask.sum() > 0 else 0.0

    print(f"Selective Decision UP (tau={best_tau}):")
    print(f"  Coverage:  {up_coverage*100:.2f}% ({up_mask.sum()} / {len(test_df)})")
    print(f"  Precision: {up_precision*100:.2f}% (vs Test prior UP { (y_test==1).mean()*100:.2f}%)")

    # 4. Paired Block Bootstrapping Lintas Tanggal Kalender Bersama
    print("\n[10] Running Paired Block Bootstrap (B=1000 calendar date blocks)...")
    np.random.seed(42)
    test_dates = test_df['bar_date'].unique()
    num_dates = len(test_dates)
    block_size = 5
    # Buat blok-blok tanggal kalender
    blocks = [test_dates[i:i+block_size] for i in range(0, num_dates, block_size)]
    num_blocks = len(blocks)

    # Indeks bar per tanggal
    date_to_indices = test_df.groupby('bar_date').indices

    B = 1000
    delta_bal_acc_maj = []
    delta_bal_acc_mom = []

    for b in range(B):
        # Sample blocks with replacement
        sampled_block_idx = np.random.choice(num_blocks, size=num_blocks, replace=True)
        sampled_indices = []
        for s_idx in sampled_block_idx:
            for d in blocks[s_idx]:
                if d in date_to_indices:
                    sampled_indices.extend(date_to_indices[d])
        sampled_indices = np.array(sampled_indices)

        b_y_true = y_test[sampled_indices]
        b_y_model = test_preds[sampled_indices]
        b_y_maj = maj_preds[sampled_indices]
        b_y_mom = mom_preds[sampled_indices]

        acc_model = balanced_accuracy_score(b_y_true, b_y_model)
        acc_maj = balanced_accuracy_score(b_y_true, b_y_maj)
        acc_mom = balanced_accuracy_score(b_y_true, b_y_mom)

        delta_bal_acc_maj.append(acc_model - acc_maj)
        delta_bal_acc_mom.append(acc_model - acc_mom)

    delta_bal_acc_maj = np.array(delta_bal_acc_maj)
    delta_bal_acc_mom = np.array(delta_bal_acc_mom)

    ci_maj_low, ci_maj_high = np.percentile(delta_bal_acc_maj, [2.5, 97.5])
    p_maj_fail = (delta_bal_acc_maj <= 0).mean()

    ci_mom_low, ci_mom_high = np.percentile(delta_bal_acc_mom, [2.5, 97.5])
    p_mom_fail = (delta_bal_acc_mom <= 0).mean()

    print(f"Paired Block Bootstrap vs Majority Baseline (B=1000):")
    print(f"  Mean Delta: {delta_bal_acc_maj.mean():+.4f}")
    print(f"  95% CI: [{ci_maj_low:+.4f}, {ci_maj_high:+.4f}]")
    print(f"  P(Model <= Majority): {p_maj_fail*100:.2f}%")

    print(f"\nPaired Block Bootstrap vs Naive Momentum Baseline (B=1000):")
    print(f"  Mean Delta: {delta_bal_acc_mom.mean():+.4f}")
    print(f"  95% CI: [{ci_mom_low:+.4f}, {ci_mom_high:+.4f}]")
    print(f"  P(Model <= Momentum): {p_mom_fail*100:.2f}%")

    # 5. Simulasi Transaksi Riil dengan Friksi Lengkap (NO SHORT POSITIONS)
    print("\n[11] Running Realistic Transaction Simulation on Test Set (LONG ONLY)...")
    # Hanya posisi LONG jika sinyal model terarah UP (prob >= best_tau)
    # Entry: Open t+1 + 1 tick slippage
    # Exit: Close t+5 - 1 tick slippage
    # Fee: 0.15% buy, 0.25% sell

    test_df_eval = test_df.copy()
    test_df_eval['p_up'] = test_p_up
    test_df_eval['pred_class'] = test_preds

    # Filter trades yang terpicu sinyal UP selektif
    trade_mask = (test_df_eval['p_up'] >= best_tau)
    trades = test_df_eval[trade_mask].copy()

    # Hitung tick size untuk entry dan exit
    entry_prices = trades['t_plus_1_open'].values
    exit_prices = trades['t_plus_5_close'].values

    entry_ticks = np.array([get_tick_size(p) for p in entry_prices])
    exit_ticks = np.array([get_tick_size(p) for p in exit_prices])

    # Fill prices with 1 tick adverse slippage per side
    fill_entry = entry_prices + entry_ticks
    fill_exit = exit_prices - exit_ticks

    # Nominal return pasca-biaya (0.15% fee beli, 0.25% fee jual)
    # Net Return = (Fill_exit * (1 - 0.0025) - Fill_entry * (1 + 0.0015)) / (Fill_entry * (1 + 0.0015))
    cost_entry = fill_entry * (1.0 + 0.0015)
    net_proceeds_exit = fill_exit * (1.0 - 0.0025)
    net_returns = (net_proceeds_exit - cost_entry) / cost_entry
    trades['net_return'] = net_returns

    gross_returns = (exit_prices - entry_prices) / entry_prices
    trades['gross_return'] = gross_returns

    num_trades = len(trades)
    win_rate = (net_returns > 0).mean() if num_trades > 0 else 0.0
    mean_net_return = net_returns.mean() if num_trades > 0 else 0.0
    mean_gross_return = gross_returns.mean() if num_trades > 0 else 0.0
    total_net_pnl = net_returns.sum() if num_trades > 0 else 0.0

    print(f"Total Trades Triggered:  {num_trades} (from {len(test_df)} test bars)")
    print(f"Mean Gross Return:       {mean_gross_return*100:+.2f}%")
    print(f"Mean Net Return:         {mean_net_return*100:+.2f}%")
    print(f"Net Win Rate (> 0):      {win_rate*100:.2f}%")
    print(f"Cumulative Simple Net:   {total_net_pnl*100:+.2f}%")

    # Evaluasi per kelompok likuiditas (Turnover Quintiles)
    test_df_eval['turnover_quintile'] = pd.qcut(test_df_eval['turnover_idr'], 5, labels=['Q1 (Lowest)', 'Q2', 'Q3', 'Q4', 'Q5 (Highest)'])
    trades['turnover_quintile'] = test_df_eval.loc[trades.index, 'turnover_quintile']

    print("\nPerformance per Turnover Quintile (Liquidity Breakdown):")
    for q, grp in trades.groupby('turnover_quintile', observed=False):
        q_trades = len(grp)
        q_net = grp['net_return'].mean() * 100 if q_trades > 0 else 0.0
        q_win = (grp['net_return'] > 0).mean() * 100 if q_trades > 0 else 0.0
        print(f"  {q}: N={q_trades}, WinRate={q_win:.1f}%, MeanNetReturn={q_net:+.2f}%")

    # Evaluasi Acceptance Gate
    gate_1 = (test_bal_acc > 0.540)
    gate_2 = (test_roc_auc >= 0.580)
    gate_3 = (ci_maj_low > 0.0 and p_maj_fail < 0.05)
    gate_4 = (mean_net_return > 0.0)

    print("\n" + "="*70)
    print("[12] ACCEPTANCE GATE VERIFICATION")
    print("="*70)
    print(f"Gate 1 - Balanced Accuracy > 54.0%:       {'PASS' if gate_1 else 'FAIL'} ({test_bal_acc*100:.2f}%)")
    print(f"Gate 2 - Macro ROC-AUC >= 0.580:          {'PASS' if gate_2 else 'FAIL'} ({test_roc_auc:.4f})")
    print(f"Gate 3 - Paired Bootstrap 95% CI > 0:     {'PASS' if gate_3 else 'FAIL'} (CI: [{ci_maj_low:+.4f}, {ci_maj_high:+.4f}], P={p_maj_fail*100:.2f}%)")
    print(f"Gate 4 - Positive Net Return Post-Fee:    {'PASS' if gate_4 else 'FAIL'} ({mean_net_return*100:+.2f}%)")

    final_status = "CANDIDATE_FOR_FORWARD_SHADOW" if (gate_1 and gate_2 and gate_3 and gate_4) else "REJECTED"
    print(f"\nFINAL VERDICT: {final_status}")

    # Simpan Artefak Prediksi Test
    os.makedirs('reports/artifacts_exp01', exist_ok=True)
    pred_artifact_path = 'reports/artifacts_exp01/test_predictions_exp01.csv'
    test_output_df = pd.DataFrame({
        'ticker': test_df['ticker'].values,
        'bar_date': test_df['bar_date'].values,
        'target_date': test_df['target_date'].values,
        'true_label': test_df['label_class'].values,
        'pred_class': [inv_label_map[p] for p in test_preds],
        'prob_neutral': test_probs[:, 0],
        'prob_up': test_probs[:, 1],
        'prob_down': test_probs[:, 2],
    })
    test_output_df.to_csv(pred_artifact_path, index=False)
    pred_hash = get_file_sha256(pred_artifact_path)
    print(f"[13] Test Prediction Artifact Saved: {pred_artifact_path} (SHA-256: {pred_hash})")

    # Simpan Summary JSON
    summary_data = {
        'experiment_id': 'SWING_OHLCV_EXP_01',
        'execution_date_utc': pd.Timestamp.now('UTC').isoformat(),
        'dataset_hash': dataset_hash,
        'prediction_artifact_hash': pred_hash,
        'best_hyperparameters': best_config,
        'best_tau_up': best_tau,
        'evaluation_metrics': {
            'test_balanced_accuracy': float(test_bal_acc),
            'test_macro_f1': float(test_macro_f1),
            'test_macro_roc_auc': float(test_roc_auc),
            'majority_baseline_balanced_accuracy': float(maj_bal_acc),
            'momentum_baseline_balanced_accuracy': float(mom_bal_acc),
            'confusion_matrix': cm.tolist(),
            'bootstrap_maj_ci_low': float(ci_maj_low),
            'bootstrap_maj_ci_high': float(ci_maj_high),
            'bootstrap_maj_p_fail': float(p_maj_fail),
            'bootstrap_mom_ci_low': float(ci_mom_low),
            'bootstrap_mom_ci_high': float(ci_mom_high),
            'bootstrap_mom_p_fail': float(p_mom_fail),
        },
        'transaction_simulation': {
            'total_trades': int(num_trades),
            'mean_gross_return': float(mean_gross_return),
            'mean_net_return': float(mean_net_return),
            'win_rate': float(win_rate),
            'total_net_pnl': float(total_net_pnl),
        },
        'acceptance_gates': {
            'gate_1_bal_acc': bool(gate_1),
            'gate_2_roc_auc': bool(gate_2),
            'gate_3_bootstrap': bool(gate_3),
            'gate_4_net_return': bool(gate_4),
        },
        'final_verdict': final_status,
        'duration_seconds': float(time.time() - start_time)
    }

    summary_json_path = 'reports/artifacts_exp01/experiment_01_summary.json'
    with open(summary_json_path, 'w') as f:
        json.dump(summary_data, f, indent=2)

    print(f"[14] Summary JSON Saved: {summary_json_path}")

if __name__ == '__main__':
    main()
