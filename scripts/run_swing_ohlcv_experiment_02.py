"""
Eksperimen Offline Swing OHLCV Kedua (EXP-02)
Perbandingan Terkendali:
Ablation A: Model Linier Existing + Dynamic Cost-Aware Hurdle Labeling (Full Universe)
Ablation B: Ablation A pada Subset Lolos Filter Eksekusi Point-in-Time (Close >= 200, Turnover20D >= 500M)
Ablation C: Non-Linear Regularized Model (HistGradientBoostingClassifier) pada Subset B

Strictly Train/Validation tuning. Evaluasi Test Mei-Okt 2026 sebagai exposed benchmark.
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
from sklearn.ensemble import HistGradientBoostingClassifier
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

def evaluate_ablation(name, model, X_test, y_test, df_test_slice, feature_cols, tau_up, maj_class):
    # 1. Klasifikasi Metrik
    test_preds = model.predict(X_test)
    test_probs = model.predict_proba(X_test)

    bal_acc = balanced_accuracy_score(y_test, test_preds)
    macro_f1 = f1_score(y_test, test_preds, average='macro')
    # One-vs-Rest ROC-AUC
    try:
        macro_auc = roc_auc_score(y_test, test_probs, multi_class='ovr', average='macro')
    except Exception:
        macro_auc = 0.5000

    cm = confusion_matrix(y_test, test_preds, labels=[0, 1, 2])
    # 0=NEUTRAL, 1=UP, 2=DOWN

    # Baseline Majority
    maj_preds = np.full_like(y_test, maj_class)
    maj_bal_acc = balanced_accuracy_score(y_test, maj_preds)

    # Baseline Momentum (close_to_sma20 > close_to_sma50 -> UP=1, else DOWN=2)
    mom_preds = np.where(df_test_slice['close_to_sma20'].values > df_test_slice['close_to_sma50'].values, 1, 2)
    mom_bal_acc = balanced_accuracy_score(y_test, mom_preds)

    # Selective Decision UP (tau_up)
    p_up = test_probs[:, 1]
    up_mask = (p_up >= tau_up)
    cov = up_mask.mean()
    prec = (y_test[up_mask] == 1).mean() if up_mask.sum() > 0 else 0.0

    # 2. Simulasi Transaksi Riil
    trades = df_test_slice[up_mask].copy()
    entry_p = trades['t_plus_1_open'].values
    exit_p = trades['t_plus_5_close'].values
    entry_ticks = np.array([get_tick_size(p) for p in entry_p])
    exit_ticks = np.array([get_tick_size(p) for p in exit_p])

    fill_entry = entry_p + entry_ticks
    fill_exit = exit_p - exit_ticks

    cost_entry = fill_entry * (1.0 + 0.0015)
    net_proceeds = fill_exit * (1.0 - 0.0025)
    net_returns = (net_proceeds - cost_entry) / cost_entry
    gross_returns = (exit_p - entry_p) / entry_p

    n_trades = len(trades)
    mean_gross = gross_returns.mean() if n_trades > 0 else 0.0
    mean_net = net_returns.mean() if n_trades > 0 else 0.0
    win_rate = (net_returns > 0).mean() if n_trades > 0 else 0.0

    # Paired Block Bootstrap vs Majority (B=500 untuk efisiensi)
    np.random.seed(42)
    test_dates = df_test_slice['bar_date'].unique()
    num_dates = len(test_dates)
    blocks = [test_dates[i:i+5] for i in range(0, num_dates, 5)]
    num_blocks = len(blocks)
    date_to_indices = df_test_slice.groupby('bar_date').indices

    delta_bal_acc = []
    for _ in range(500):
        s_blocks = np.random.choice(num_blocks, size=num_blocks, replace=True)
        s_indices = []
        for sb in s_blocks:
            for d in blocks[sb]:
                if d in date_to_indices:
                    s_indices.extend(date_to_indices[d])
        s_indices = np.array(s_indices)
        if len(s_indices) == 0:
            continue
        acc_m = balanced_accuracy_score(y_test[s_indices], test_preds[s_indices])
        acc_maj = balanced_accuracy_score(y_test[s_indices], maj_preds[s_indices])
        delta_bal_acc.append(acc_m - acc_maj)

    delta_bal_acc = np.array(delta_bal_acc)
    ci_low, ci_high = np.percentile(delta_bal_acc, [2.5, 97.5])
    p_fail = (delta_bal_acc <= 0).mean()

    return {
        'name': name,
        'test_samples': len(y_test),
        'bal_acc': float(bal_acc),
        'macro_f1': float(macro_f1),
        'macro_auc': float(macro_auc),
        'maj_bal_acc': float(maj_bal_acc),
        'mom_bal_acc': float(mom_bal_acc),
        'tau_up': float(tau_up),
        'coverage_up': float(cov),
        'precision_up': float(prec),
        'trades_count': int(n_trades),
        'mean_gross_return': float(mean_gross),
        'mean_net_return': float(mean_net),
        'win_rate': float(win_rate),
        'cm': cm.tolist(),
        'bootstrap_ci_low': float(ci_low),
        'bootstrap_ci_high': float(ci_high),
        'bootstrap_p_fail': float(p_fail),
    }

def main():
    start_time = time.time()
    csv_path = 'reports/swing_ohlcv_dataset_exp02.csv'
    dataset_hash = get_file_sha256(csv_path)
    print(f"[1] Dataset Hash: {dataset_hash}")

    df = pd.read_csv(csv_path)
    print(f"[2] Loaded Dataset: {len(df)} rows across {df['ticker'].nunique()} tickers.")

    # Mapping Label Dynamic: NEUTRAL=0, UP=1, DOWN=2
    label_map = {'NEUTRAL': 0, 'UP': 1, 'DOWN': 2}
    df['label_idx'] = df['label_dynamic_exp02'].map(label_map)

    feature_cols = [
        'ret_1d', 'ret_5d', 'ret_20d',
        'close_to_sma20', 'close_to_sma50',
        'atr14_norm', 'hl_spread_norm',
        'vol_to_sma20', 'log_estimated_turnover'
    ]

    train_df = df[df['split'] == 'TRAIN'].copy()
    val_df = df[df['split'] == 'VALIDATION'].copy()
    test_df = df[df['split'] == 'TEST'].copy()

    # Hitung Majority class dari Train Dynamic Label
    maj_class = int(train_df['label_idx'].mode()[0])
    print(f"[3] Train Majority Class (Dynamic Label): {maj_class} ({ {0:'NEUTRAL', 1:'UP', 2:'DOWN'}[maj_class] })")

    # =========================================================================
    # ABLATION A: Model Linier + Dynamic Hurdle (Full Universe)
    # =========================================================================
    print("\n" + "="*70)
    print("RUNNING ABLATION A: Logistic Regression + Dynamic Hurdle (Full Universe)")
    print("="*70)

    scaler_a = StandardScaler()
    X_train_a = scaler_a.fit_transform(train_df[feature_cols].values)
    y_train_a = train_df['label_idx'].values

    X_val_a = scaler_a.transform(val_df[feature_cols].values)
    y_val_a = val_df['label_idx'].values

    X_test_a = scaler_a.transform(test_df[feature_cols].values)
    y_test_a = test_df['label_idx'].values

    # Tuning C dan class_weight pada Validation Only
    print("Tuning Ablation A on Validation...")
    best_score_a = -1.0
    best_clf_a = None
    best_cfg_a = None
    for cfg in [
        {'C': 0.01, 'class_weight': 'balanced'},
        {'C': 0.1, 'class_weight': 'balanced'},
        {'C': 1.0, 'class_weight': 'balanced'},
    ]:
        clf = LogisticRegression(C=cfg['C'], class_weight=cfg['class_weight'], solver='lbfgs', max_iter=500, random_state=42)
        clf.fit(X_train_a, y_train_a)
        score = balanced_accuracy_score(y_val_a, clf.predict(X_val_a))
        if score > best_score_a:
            best_score_a = score
            best_clf_a = clf
            best_cfg_a = cfg

    print(f"Best Config Ablation A: {best_cfg_a} (Val BalAcc: {best_score_a:.4f})")

    # Tuning tau_up pada Validation
    val_probs_a = best_clf_a.predict_proba(X_val_a)
    best_tau_a = 0.33
    best_prec_a = 0.0
    for tau in [0.25, 0.30, 0.33, 0.38, 0.42]:
        m = (val_probs_a[:, 1] >= tau)
        if m.sum() >= 1000:
            prec = (y_val_a[m] == 1).mean()
            if prec > best_prec_a:
                best_prec_a = prec
                best_tau_a = tau

    print(f"Chosen tau_up Ablation A: {best_tau_a}")
    res_a = evaluate_ablation("Ablation A (Full Universe Linear + Dynamic Hurdle)", best_clf_a, X_test_a, y_test_a, test_df, feature_cols, best_tau_a, maj_class)

    # =========================================================================
    # ABLATION B: Ablation A pada Subset Lolos Filter Eksekusi Point-in-Time
    # Filter: Close >= 200 dan Turnover20D SMA >= 500 Juta IDR
    # =========================================================================
    print("\n" + "="*70)
    print("RUNNING ABLATION B: Model A pada Common Execution-Eligible Subset")
    print("="*70)

    # Filter diterapkan pada test set
    test_mask_b = (test_df['eligibility_filter_b'] == 1)
    test_b = test_df[test_mask_b].copy()
    X_test_b = X_test_a[test_mask_b]
    y_test_b = y_test_a[test_mask_b]

    print(f"Universe Test Total: {len(test_df)} -> Eligible B Subset: {len(test_b)} ({len(test_b)/len(test_df)*100:.2f}%)")
    res_b = evaluate_ablation("Ablation B (Eligible Subset Linear + Dynamic Hurdle)", best_clf_a, X_test_b, y_test_b, test_b, feature_cols, best_tau_a, maj_class)

    # =========================================================================
    # ABLATION C: Non-Linear Regularized Model (HistGradientBoosting) pada Subset B
    # =========================================================================
    print("\n" + "="*70)
    print("RUNNING ABLATION C: HistGradientBoosting on Execution-Eligible Subset")
    print("="*70)

    # Fit HistGradientBoosting strictly on TRAIN (subset eligible B)
    train_mask_b = (train_df['eligibility_filter_b'] == 1)
    val_mask_b = (val_df['eligibility_filter_b'] == 1)

    train_b = train_df[train_mask_b].copy()
    val_b = val_df[val_mask_b].copy()

    X_train_c = train_b[feature_cols].values
    y_train_c = train_b['label_idx'].values

    X_val_c = val_b[feature_cols].values
    y_val_c = val_b['label_idx'].values

    X_test_c = test_b[feature_cols].values
    y_test_c = test_b['label_idx'].values

    print(f"Train B rows: {len(train_b)}, Val B rows: {len(val_b)}, Test B rows: {len(test_b)}")

    # Tuning terbatas HistGradientBoosting on Validation Only (Budget: 4 fits)
    print("Tuning HistGradientBoosting on Validation B...")
    best_score_c = -1.0
    best_clf_c = None
    best_cfg_c = None

    for cfg in [
        {'max_iter': 100, 'max_leaf_nodes': 15, 'l2_regularization': 1.0, 'learning_rate': 0.05},
        {'max_iter': 100, 'max_leaf_nodes': 31, 'l2_regularization': 1.0, 'learning_rate': 0.05},
        {'max_iter': 150, 'max_leaf_nodes': 15, 'l2_regularization': 5.0, 'learning_rate': 0.03},
        {'max_iter': 150, 'max_leaf_nodes': 31, 'l2_regularization': 5.0, 'learning_rate': 0.03},
    ]:
        hgb = HistGradientBoostingClassifier(
            max_iter=cfg['max_iter'],
            max_leaf_nodes=cfg['max_leaf_nodes'],
            l2_regularization=cfg['l2_regularization'],
            learning_rate=cfg['learning_rate'],
            class_weight='balanced',
            random_state=42
        )
        hgb.fit(X_train_c, y_train_c)
        score = balanced_accuracy_score(y_val_c, hgb.predict(X_val_c))
        print(f"  Cfg: {cfg} -> Val BalAcc: {score:.4f}")
        if score > best_score_c:
            best_score_c = score
            best_clf_c = hgb
            best_cfg_c = cfg

    print(f"Best Config Ablation C: {best_cfg_c} (Val BalAcc: {best_score_c:.4f})")

    # Tuning tau_up pada Validation C
    val_probs_c = best_clf_c.predict_proba(X_val_c)
    best_tau_c = 0.33
    best_prec_c = 0.0
    for tau in [0.25, 0.30, 0.33, 0.38, 0.42]:
        m = (val_probs_c[:, 1] >= tau)
        if m.sum() >= 500:
            prec = (y_val_c[m] == 1).mean()
            if prec > best_prec_c:
                best_prec_c = prec
                best_tau_c = tau

    print(f"Chosen tau_up Ablation C: {best_tau_c}")
    res_c = evaluate_ablation("Ablation C (Eligible Subset HistGradientBoosting + Dynamic Hurdle)", best_clf_c, X_test_c, y_test_c, test_b, feature_cols, best_tau_c, maj_class)

    # Cetak Hasil Komparasi
    print("\n" + "="*80)
    print("HASIL KOMPARASI KONTROL ABLATION A / B / C (TEST MEI - OKT 2026)")
    print("="*80)
    for r in [res_a, res_b, res_c]:
        print(f"--- {r['name']} ---")
        print(f"  Sampel Test: {r['test_samples']}")
        print(f"  Balanced Accuracy: {r['bal_acc']*100:.2f}% (Majority Baseline: {r['maj_bal_acc']*100:.2f}%)")
        print(f"  Macro ROC-AUC:     {r['macro_auc']:.4f} | Macro F1: {r['macro_f1']:.4f}")
        print(f"  Decision UP (tau={r['tau_up']}): Coverage={r['coverage_up']*100:.2f}% ({r['trades_count']} trades), Precision={r['precision_up']*100:.2f}%")
        print(f"  Simulasi Transaksi: Mean Gross Return={r['mean_gross_return']*100:+.2f}%, Mean Net Return={r['mean_net_return']*100:+.2f}%, WinRate={r['win_rate']*100:.2f}%")
        print(f"  Paired Bootstrap vs Majority: 95% CI [{r['bootstrap_ci_low']:+.4f}, {r['bootstrap_ci_high']:+.4f}], P(Fail)={r['bootstrap_p_fail']*100:.2f}%\n")

    # Verifikasi Acceptance Gate untuk Setiap Ablation
    print("="*80)
    print("VERIFIKASI ACCEPTANCE GATES EXP-02")
    print("="*80)
    for r in [res_a, res_b, res_c]:
        g1 = r['bal_acc'] > 0.540
        g2 = r['macro_auc'] >= 0.580
        g3 = (r['bootstrap_ci_low'] > 0 and r['bootstrap_p_fail'] < 0.05)
        g4 = r['mean_net_return'] > 0.0
        passed_all = (g1 and g2 and g3 and g4)
        print(f"{r['name']}:")
        print(f"  Gate 1 (BalAcc > 54%):      {'PASS' if g1 else 'FAIL'} ({r['bal_acc']*100:.2f}%)")
        print(f"  Gate 2 (ROC-AUC >= 0.58):   {'PASS' if g2 else 'FAIL'} ({r['macro_auc']:.4f})")
        print(f"  Gate 3 (Bootstrap 95% > 0): {'PASS' if g3 else 'FAIL'} (CI [{r['bootstrap_ci_low']:+.4f}, {r['bootstrap_ci_high']:+.4f}])")
        print(f"  Gate 4 (Positive Net Ret):  {'PASS' if g4 else 'FAIL'} ({r['mean_net_return']*100:+.2f}%)")
        print(f"  Status: {'CANDIDATE_FOR_FORWARD_SHADOW' if passed_all else 'REJECTED'}\n")

    # Simpan Artefak Prediksi Test EXP-02
    os.makedirs('reports/artifacts_exp02', exist_ok=True)
    test_b_preds_c = best_clf_c.predict(X_test_c)
    test_b_probs_c = best_clf_c.predict_proba(X_test_c)

    pred_b_path = 'reports/artifacts_exp02/test_predictions_exp02_ablation_c.csv'
    pd.DataFrame({
        'ticker': test_b['ticker'].values,
        'bar_date': test_b['bar_date'].values,
        'target_date': test_b['target_date'].values,
        'true_label': test_b['label_dynamic_exp02'].values,
        'pred_class': [ {0:'NEUTRAL', 1:'UP', 2:'DOWN'}[p] for p in test_b_preds_c ],
        'prob_neutral': test_b_probs_c[:, 0],
        'prob_up': test_b_probs_c[:, 1],
        'prob_down': test_b_probs_c[:, 2],
    }).to_csv(pred_b_path, index=False)
    pred_hash = get_file_sha256(pred_b_path)
    print(f"Test Prediction Artifact Saved: {pred_b_path} (SHA-256: {pred_hash})")

    # Simpan Summary JSON
    summary_data = {
        'experiment_id': 'SWING_OHLCV_EXP_02',
        'execution_date_utc': pd.Timestamp.now('UTC').isoformat(),
        'dataset_hash': dataset_hash,
        'prediction_artifact_hash': pred_hash,
        'results': [res_a, res_b, res_c],
        'duration_seconds': float(time.time() - start_time)
    }
    summary_json_path = 'reports/artifacts_exp02/experiment_02_summary.json'
    with open(summary_json_path, 'w') as f:
        json.dump(summary_data, f, indent=2)
    print(f"Summary JSON Saved: {summary_json_path}")

if __name__ == '__main__':
    main()
