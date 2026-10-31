"""IDX Financial & Technical Data Collector & Metric Engine.
Fetches up to 5 years daily OHLCV, corporate actions (dividends, splits),
and financial statements (annual, quarterly) via yfinance.
Calculates deterministic technical and fundamental metrics.
Zero LLM hallucination: all metrics computed in deterministic code.
"""

import sys
import os
import json
import math
import time
import hashlib
import datetime
from pathlib import Path
import pandas as pd

def calculate_technical_metrics(df_hist) -> dict:
    if df_hist.empty:
        return {
            "technical_status": "INSUFFICIENT_HISTORY",
            "close_price": None,
            "return_1d_pct": None,
            "return_5d_pct": None,
            "return_20d_pct": None,
            "sma_20": None,
            "sma_50": None,
            "sma_200": None,
            "rsi_14": None,
            "macd": None,
            "macd_signal": None,
            "macd_hist": None,
            "atr_14": None,
            "atr_14_pct": None,
            "rvol_20": None,
            "adtv_20": None,
            "zero_vol_days_20": None,
            "max_drawdown_1y_pct": None,
            "support_price": None,
            "resistance_price": None,
            "technical_trend": "INSUFFICIENT_HISTORY",
            "indicators_meta": {
                "input_bars": 0,
                "sma20_sufficient": False,
                "sma50_sufficient": False,
                "sma200_sufficient": False,
                "drawdown_sufficient": False,
                "rsi_sufficient": False,
                "macd_sufficient": False,
                "atr_sufficient": False,
                "adtv_sufficient": False,
            },
        }

    closes = df_hist["Close"].astype(float)
    highs = df_hist["High"].astype(float)
    lows = df_hist["Low"].astype(float)
    volumes = df_hist["Volume"].astype(float)

    n = len(closes)
    latest_close = float(closes.iloc[-1])

    # Returns
    ret_1d = round(((latest_close - float(closes.iloc[-2])) / float(closes.iloc[-2])) * 100, 2) if n >= 2 else None
    ret_5d = round(((latest_close - float(closes.iloc[-6])) / float(closes.iloc[-6])) * 100, 2) if n >= 6 else None
    ret_20d = round(((latest_close - float(closes.iloc[-21])) / float(closes.iloc[-21])) * 100, 2) if n >= 21 else None

    # SMAs (Strict minimum history requirements)
    sma_20 = round(float(closes.tail(20).mean()), 2) if n >= 20 else None
    sma_50 = round(float(closes.tail(50).mean()), 2) if n >= 50 else None
    sma_200 = round(float(closes.tail(200).mean()), 2) if n >= 200 else None

    # RSI 14 (Standard Wilder's Smoothed RMA with alpha=1/14 across all warmup bars)
    rsi_14 = None
    if n >= 15:
        deltas = closes.diff().dropna()
        gains = deltas.clip(lower=0)
        losses = -deltas.clip(upper=0)
        # Standard Wilder's RMA (exponentially smoothed across full series)
        wilder_gain = float(gains.ewm(alpha=1/14, adjust=False).mean().iloc[-1])
        wilder_loss = float(losses.ewm(alpha=1/14, adjust=False).mean().iloc[-1])
        if wilder_loss == 0:
            if wilder_gain == 0:
                rsi_14 = 50.0  # Constant price series, zero momentum -> neutral 50.0
            else:
                rsi_14 = 100.0  # Monotonically increasing or non-decreasing -> maximum 100.0
        elif wilder_gain == 0:
            rsi_14 = 0.0  # Monotonically decreasing -> minimum 0.0
        else:
            rs = wilder_gain / wilder_loss
            rsi_14 = round(100.0 - (100.0 / (1.0 + rs)), 2)

    # MACD (12, 26, 9) - Minimum warmup: requires >= 35 bars (26 + 9)
    macd = None
    macd_signal = None
    macd_hist = None
    if n >= 35:
        ema_12 = closes.ewm(span=12, adjust=False).mean()
        ema_26 = closes.ewm(span=26, adjust=False).mean()
        macd_line = ema_12 - ema_26
        signal_line = macd_line.ewm(span=9, adjust=False).mean()
        macd = round(float(macd_line.iloc[-1]), 2)
        macd_signal = round(float(signal_line.iloc[-1]), 2)
        macd_hist = round(macd - macd_signal, 2)

    # ATR 14 - Minimum warmup: requires >= 15 bars
    atr_14 = None
    atr_14_pct = None
    if n >= 15:
        tr1 = highs - lows
        tr2 = (highs - closes.shift(1)).abs()
        tr3 = (lows - closes.shift(1)).abs()
        import pandas as pd
        tr = pd.concat([tr1, tr2, tr3], axis=1).max(axis=1)
        atr_14 = round(float(tr.tail(14).mean()), 2)
        if latest_close > 0:
            atr_14_pct = round((atr_14 / latest_close) * 100, 2)

    # Relative Volume (RVOL 20)
    rvol_20 = None
    if n >= 21:
        avg_vol_20 = float(volumes.iloc[-21:-1].mean())
        latest_vol = float(volumes.iloc[-1])
        if avg_vol_20 > 0:
            rvol_20 = round(latest_vol / avg_vol_20, 2)

    # Average Daily Turnover Value (ADTV 20) and Zero Volume Days (Activity Proxy)
    adtv_20 = None
    zero_vol_days_20 = None
    if n >= 20:
        turnovers_20 = closes.tail(20) * volumes.tail(20)
        adtv_20 = round(float(turnovers_20.mean()), 2)
        zero_vol_days_20 = int((volumes.tail(20) == 0).sum())

    # Max Drawdown (1 Year Calendar Window)
    max_drawdown = None
    drawdown_meta = {"status": "INSUFFICIENT_HISTORY", "bars": 0, "window": "1_YEAR_CALENDAR"}
    if not df_hist.empty and n >= 5:
        last_dt = df_hist.index[-1]
        start_1y = last_dt - pd.DateOffset(years=1)
        sub_1y = df_hist[df_hist.index >= start_1y]
        bars_1y = len(sub_1y)
        calendar_days_spanned = (last_dt - sub_1y.index[0]).days if bars_1y > 0 else 0

        # Full 1Y requires spanning >= 340 calendar days AND >= 190 trading sessions (session sufficiency)
        if calendar_days_spanned >= 340 and bars_1y >= 190:
            sub_closes = sub_1y["Close"]
            running_max = sub_closes.cummax()
            drawdowns = (sub_closes - running_max) / running_max
            max_drawdown = round(float(drawdowns.min() * 100), 2)
            drawdown_meta = {
                "status": "FULL_1Y_CALCULATED",
                "bars": bars_1y,
                "calendar_days_spanned": calendar_days_spanned,
                "start_date": str(sub_1y.index[0])[:10],
                "end_date": str(last_dt)[:10],
                "session_sufficiency": "SUFFICIENT",
            }
        elif bars_1y >= 20 and calendar_days_spanned >= 30:
            sub_closes = sub_1y["Close"]
            running_max = sub_closes.cummax()
            drawdowns = (sub_closes - running_max) / running_max
            max_drawdown = round(float(drawdowns.min() * 100), 2)
            drawdown_meta = {
                "status": "PARTIAL_AVAILABLE",
                "bars": bars_1y,
                "calendar_days_spanned": calendar_days_spanned,
                "start_date": str(sub_1y.index[0])[:10],
                "end_date": str(last_dt)[:10],
                "session_sufficiency": "PARTIAL_SPAN",
            }

    # Support & Resistance (Rolling 20-day min/max, strictly point-in-time including current bar t)
    support_20 = round(float(lows.tail(20).min()), 2) if n >= 20 else None
    resistance_20 = round(float(highs.tail(20).max()), 2) if n >= 20 else None

    # Trend Regime
    trend = "SIDEWAYS"
    if n < 20:
        trend = "INSUFFICIENT_HISTORY"
    elif not sma_50:
        trend = "WARMUP_SHORT_HISTORY"
    else:
        if sma_20 > sma_50:
            if sma_200 is not None and sma_50 > sma_200:
                trend = "STRONG_BULLISH"
            elif sma_200 is not None:
                trend = "BULLISH"
            else:
                trend = "BULLISH (SMA200 N/A)"
        elif sma_20 < sma_50:
            if sma_200 is not None and sma_50 < sma_200:
                trend = "STRONG_BEARISH"
            elif sma_200 is not None:
                trend = "BEARISH"
            else:
                trend = "BEARISH (SMA200 N/A)"

    indicators_meta = {
        "input_bars": n,
        "sma20_sufficient": n >= 20,
        "sma50_sufficient": n >= 50,
        "sma200_sufficient": n >= 200,
        "drawdown_sufficient": drawdown_meta["status"] == "FULL_1Y_CALCULATED",
        "drawdown_meta": drawdown_meta,
        "rsi_sufficient": n >= 15,
        "macd_sufficient": n >= 35,
        "atr_sufficient": n >= 15,
        "adtv_sufficient": n >= 20,
        "adtv_definition": "ESTIMATED_DAILY_TURNOVER_CLOSE_X_VOLUME_ACTIVITY_PROXY",
    }

    return {
        "technical_status": "CALCULATED" if n >= 20 else "INSUFFICIENT_HISTORY",
        "close_price": latest_close,
        "return_1d_pct": ret_1d,
        "return_5d_pct": ret_5d,
        "return_20d_pct": ret_20d,
        "sma_20": sma_20,
        "sma_50": sma_50,
        "sma_200": sma_200,
        "rsi_14": rsi_14,
        "macd": macd,
        "macd_signal": macd_signal,
        "macd_hist": macd_hist,
        "atr_14": atr_14,
        "atr_14_pct": atr_14_pct,
        "rvol_20": rvol_20,
        "adtv_20": adtv_20,
        "zero_vol_days_20": zero_vol_days_20,
        "max_drawdown_1y_pct": max_drawdown,
        "support_price": support_20,
        "resistance_price": resistance_20,
        "technical_trend": trend,
        "indicators_meta": indicators_meta,
    }

def fetch_and_process_ticker(ticker_symbol: str, full_history: bool = False) -> dict:
    import yfinance as yf

    clean_ticker = ticker_symbol.upper().strip()
    idx_symbol = f"{clean_ticker}.JK" if clean_ticker != "^JKSE" else clean_ticker

    t0 = time.time()
    errors = []
    flags = {}

    period = "5y" if full_history else "1y"

    try:
        yf_obj = yf.Ticker(idx_symbol)

        # 1. Price History
        hist = yf_obj.history(period=period, interval="1d", auto_adjust=False)
        if hist.empty:
            return {
                "success": False,
                "ticker": clean_ticker,
                "error_type": "NO_PRICE_DATA",
                "message": f"Data harga kosong untuk {idx_symbol}",
            }

        tech_metrics = calculate_technical_metrics(hist)

        # Extract all bars up to 5 years for database storage
        all_bars = []
        for idx, row in hist.iterrows():
            bar_d = str(idx)[:10]
            all_bars.append({
                "bar_date": bar_d,
                "open": float(row["Open"]),
                "high": float(row["High"]),
                "low": float(row["Low"]),
                "close": float(row["Close"]),
                "volume": int(row["Volume"]),
            })

        # 2. Corporate Actions (Dividends & Splits)
        actions = []
        if hasattr(yf_obj, "actions") and not yf_obj.actions.empty:
            for dt, act in yf_obj.actions.iterrows():
                dt_str = str(dt)[:10]
                div = float(act.get("Dividends", 0.0))
                spl = float(act.get("Stock Splits", 0.0))
                if div > 0:
                    actions.append({
                        "action_type": "DIVIDEND",
                        "execution_date": dt_str,
                        "amount_or_ratio": div,
                    })
                if spl > 0:
                    actions.append({
                        "action_type": "STOCK_SPLIT",
                        "execution_date": dt_str,
                        "amount_or_ratio": spl,
                    })

        # 3. Fundamentals & Info
        info = {}
        try:
            info = yf_obj.info or {}
        except Exception as e:
            flags["info_fetch_error"] = str(e)

        sector = info.get("sector", "General")
        is_financial = "Financial" in sector or "Bank" in sector

        # Financial Statements
        fin_reports = []
        fin_df = getattr(yf_obj, "financials", None)
        bs_df = getattr(yf_obj, "balance_sheet", None)
        cf_df = getattr(yf_obj, "cashflow", None)

        if fin_df is not None and not fin_df.empty:
            for col in fin_df.columns:
                date_str = str(col)[:10]
                year = int(date_str[:4])
                
                def get_val(df, row_name):
                    if df is not None and not df.empty and row_name in df.index and col in df.columns:
                        try:
                            v = df.loc[row_name, col]
                            return float(v) if not math.isnan(float(v)) else None
                        except Exception:
                            return None
                    return None

                rev = get_val(fin_df, "Total Revenue")
                gp = get_val(fin_df, "Gross Profit")
                op = get_val(fin_df, "Operating Income")
                ni = get_val(fin_df, "Net Income") or get_val(fin_df, "Net Income Common Stockholders")
                ni_parent = get_val(fin_df, "Net Income Common Stockholders") or ni

                assets = get_val(bs_df, "Total Assets")
                liab = get_val(bs_df, "Total Liabilities Net Minority Interest") or get_val(bs_df, "Total Liabilities")
                equity = get_val(bs_df, "Stockholders Equity") or get_val(bs_df, "Common Stock Equity")
                eq_parent = get_val(bs_df, "Stockholders Equity") or equity
                debt = get_val(bs_df, "Total Debt")
                lt_debt = get_val(bs_df, "Long Term Debt")
                st_debt = get_val(bs_df, "Current Debt")
                lease_obl = get_val(bs_df, "Capital Lease Obligations") or 0.0
                pure_debt = (lt_debt or 0.0) + (st_debt or 0.0) if (lt_debt is not None or st_debt is not None) else None
                
                ord_shares = get_val(bs_df, "Ordinary Shares Number")
                basic_avg_shares = get_val(fin_df, "Basic Average Shares")
                rep_basic_eps = get_val(fin_df, "Basic EPS")
                rep_diluted_eps = get_val(fin_df, "Diluted EPS")

                ocf = get_val(cf_df, "Operating Cash Flow")
                capex = get_val(cf_df, "Capital Expenditure")
                fcf = get_val(cf_df, "Free Cash Flow")

                fin_reports.append({
                    "report_type": "ANNUAL",
                    "fiscal_year": year,
                    "fiscal_quarter": None,
                    "period_end_date": date_str,
                    "currency": "IDR",
                    "total_revenue": rev,
                    "gross_profit": gp,
                    "operating_income": op,
                    "net_income": ni,
                    "net_income_parent": ni_parent,
                    "total_assets": assets,
                    "total_liabilities": liab,
                    "total_equity": equity,
                    "total_equity_parent": eq_parent,
                    "total_debt": debt,
                    "long_term_debt": lt_debt,
                    "current_debt": st_debt,
                    "capital_lease_obligations": lease_obl,
                    "pure_debt": pure_debt,
                    "ordinary_shares_number": ord_shares,
                    "basic_average_shares": basic_avg_shares,
                    "basic_eps": rep_basic_eps,
                    "diluted_eps": rep_diluted_eps,
                    "operating_cash_flow": ocf,
                    "capital_expenditure": capex,
                    "free_cash_flow": fcf,
                    "source": "Yahoo Finance (via yfinance)",
                })

        # Calculate Fundamental Metrics
        latest_close = tech_metrics["close_price"] or 0.0

        pe_ratio = None
        pbv_ratio = None
        roe_pct = None
        roa_pct = None
        der_ratio = None
        der_pure_ratio = None
        liab_to_eq_ratio = None
        npm_pct = None
        opm_pct = None
        rev_growth_yoy = None
        ni_growth_yoy = None
        div_yield_pct = None
        div_cash_amount = None
        latest_div_date = None
        eps = None
        bvps = None
        shares_out = None
        weighted_avg_shares = None

        eps_source = "N/A"
        bvps_source = "N/A"
        pe_source = "N/A"
        pbv_source = "N/A"
        der_source = "N/A"
        div_source = "N/A"

        if fin_reports:
            latest_rep = fin_reports[0]
            ni = latest_rep["net_income"]
            ni_parent = latest_rep.get("net_income_parent") or ni
            eq = latest_rep["total_equity"]
            eq_parent = latest_rep.get("total_equity_parent") or eq
            assets = latest_rep["total_assets"]
            rev = latest_rep["total_revenue"]
            op = latest_rep["operating_income"]
            debt = latest_rep["total_debt"]
            tot_liab = latest_rep.get("total_liabilities")

            shares_out = latest_rep.get("ordinary_shares_number") or info.get("impliedSharesOutstanding") or info.get("sharesOutstanding") or None
            weighted_avg_shares = latest_rep.get("basic_average_shares") or None
            rep_basic_eps = latest_rep.get("basic_eps")

            # Growth YoY if at least 2 annual reports
            if len(fin_reports) >= 2:
                prev_rep = fin_reports[1]
                if rev and prev_rep["total_revenue"] and prev_rep["total_revenue"] > 0:
                    rev_growth_yoy = round(((rev - prev_rep["total_revenue"]) / prev_rep["total_revenue"]) * 100, 2)
                if ni and prev_rep["net_income"] and abs(prev_rep["net_income"]) > 0:
                    ni_growth_yoy = round(((ni - prev_rep["net_income"]) / abs(prev_rep["net_income"])) * 100, 2)

            # 1. EPS: Prioritize official reported Basic EPS or Net Income Parent / Weighted Average Shares
            if rep_basic_eps is not None:
                eps = round(float(rep_basic_eps), 2)
                eps_source = "REPORTED_BASIC_EPS (Laporan Keuangan)"
            elif ni_parent is not None and weighted_avg_shares and weighted_avg_shares > 0:
                eps = round(float(ni_parent / weighted_avg_shares), 2)
                eps_source = "CALCULATED_WEIGHTED_AVG_SHARES"
            elif ni_parent is not None and shares_out and shares_out > 0:
                eps = round(float(ni_parent / shares_out), 2)
                eps_source = "CALCULATED_PERIOD_END_SHARES"
            elif info.get("trailingEps"):
                eps = round(float(info["trailingEps"]), 2)
                eps_source = "PROVIDER_SUPPLIED"

            # 2. BVPS: Prioritize Equity Parent / Ordinary Shares Number at Balance Sheet Date
            if eq_parent is not None and shares_out and shares_out > 0:
                bvps = round(float(eq_parent / shares_out), 2)
                bvps_source = "CALCULATED_FROM_EQUITY_PARENT_AND_ORDINARY_SHARES"
            elif eq is not None and shares_out and shares_out > 0:
                bvps = round(float(eq / shares_out), 2)
                bvps_source = "CALCULATED_FROM_TOTAL_EQUITY"
            elif info.get("bookValue"):
                bvps = round(float(info["bookValue"]), 2)
                bvps_source = "PROVIDER_SUPPLIED"

            # 3. Ratios: PER & PBV
            if latest_close > 0 and eps is not None:
                if eps > 0:
                    pe_ratio = round(float(latest_close / eps), 2)
                    pe_source = "CALCULATED_FROM_PRICE_AND_EPS"
                else:
                    pe_ratio = None
                    pe_source = "NOT_APPLICABLE"
                    flags["pe_metric_status"] = "NOT_APPLICABLE_NEGATIVE_EARNINGS"
                    flags["negative_pe"] = "PER NOT_APPLICABLE: Emiten mencatat rugi bersih (EPS <= 0), bukan tanda valuasi murah"

            if latest_close > 0 and bvps and bvps > 0:
                pbv_ratio = round(float(latest_close / bvps), 2)
                pbv_source = "CALCULATED_FROM_PRICE_AND_BVPS"

            if ni_parent is not None and eq_parent is not None and eq_parent > 0:
                roe_pct = round(float((ni_parent / eq_parent) * 100), 2)
            elif ni is not None and eq is not None and eq > 0:
                roe_pct = round(float((ni / eq) * 100), 2)

            if ni is not None and assets is not None and assets > 0:
                roa_pct = round(float((ni / assets) * 100), 2)

            # 4. Solvency: DER (Interest-Bearing) vs Total Liabilities to Equity
            der_pure_ratio = None
            if is_financial:
                der_ratio = None
                der_pure_ratio = None
                liab_to_eq_ratio = None
                der_source = "EXCLUDED_BANKING_TEMPLATE"
                flags["der_status"] = "DER tidak relevan untuk sektor perbankan/keuangan (DPK liabilitas operasional)"
            else:
                if debt is not None and eq_parent is not None and eq_parent > 0:
                    der_ratio = round(float(debt / eq_parent), 2)
                    der_source = "INTEREST_BEARING_DEBT_TO_EQUITY"
                pure_debt_val = latest_rep.get("pure_debt")
                if pure_debt_val and eq_parent and eq_parent > 0:
                    der_pure_ratio = round(float(pure_debt_val / eq_parent), 2)
                if tot_liab is not None and eq_parent is not None and eq_parent > 0:
                    liab_to_eq_ratio = round(float(tot_liab / eq_parent), 2)

            if ni is not None and rev is not None and rev > 0:
                npm_pct = round(float((ni / rev) * 100), 2)

            if op is not None and rev is not None and rev > 0:
                opm_pct = round(float((op / rev) * 100), 2)

            # 5. Dividend Yield (Trailing Cash Distribution in Last 12 Months)
            if actions:
                one_year_ago = (datetime.datetime.now() - datetime.timedelta(days=365)).strftime("%Y-%m-%d")
                recent_div_acts = [a for a in actions if a["action_type"] == "DIVIDEND" and a["execution_date"] >= one_year_ago]
                if recent_div_acts:
                    recent_divs = sum(a["amount_or_ratio"] for a in recent_div_acts)
                    div_cash_amount = round(float(recent_divs), 4)
                    latest_div_date = max(a["execution_date"] for a in recent_div_acts)
                    if latest_close > 0 and recent_divs > 0:
                        div_yield_pct = round(float((recent_divs / latest_close) * 100), 2)
                        div_source = "PROVIDER_SUPPLIED_DIVIDEND_FEED"

            if div_yield_pct is None and info.get("dividendYield"):
                val = float(info["dividendYield"])
                div_yield_pct = round(val if val > 0.5 else val * 100, 2)
                div_source = "PROVIDER_SUPPLIED_INFO"

        sector_template = "BANKING" if is_financial else "GENERAL"

        # Check currency and partial reasons
        fin_curr = info.get("financialCurrency") or "IDR"
        partial_reason = None
        missing_components = []
        is_retryable = False

        if fin_curr != "IDR" and fin_curr is not None:
            fund_status = "PARTIAL"
            partial_reason = f"CURRENCY_CONVERSION_REQUIRED_{fin_curr}_TO_IDR"
            missing_components = ["idr_normalized_valuation_ratios", "exchange_rate"]
            is_retryable = False  # Upstream data is static, retrying won't convert currency
        elif fin_reports and (pe_ratio is not None or (eps is not None and eps <= 0)):
            # Positive earnings OR negative earnings with PE marked NOT_APPLICABLE: Full calculated!
            fund_status = "CALCULATED"
        elif fin_reports:
            fund_status = "PARTIAL"
            partial_reason = "INCOMPLETE_UPSTREAM_METRIC_FIELDS"
            if pe_ratio is None and (eps is None or eps > 0): missing_components.append("pe_ratio")
            if pbv_ratio is None: missing_components.append("pbv_ratio")
            if roe_pct is None: missing_components.append("roe_pct")
            is_retryable = False
        else:
            fund_status = "NO_DATA"
            # Distinguish: Not found on checked provider vs Inaccessible source vs Document not yet published
            partial_reason = "NOT_FOUND_ON_CHECKED_PROVIDER_YFINANCE"
            missing_components = ["balance_sheet", "income_statement"]
            flags["provider_checked"] = "Yahoo Finance (yfinance)"
            flags["coverage_classification"] = "NOT_FOUND_ON_CHECKED_PROVIDER"
            flags["coverage_rationale"] = "Data laporan keuangan belum ditemukan pada feed yfinance; bukan kesimpulan bahwa dokumen fisik BEI belum terbit atau tidak ada."
            flags["recheck_schedule"] = "ON_NEW_SOURCE_OR_DOCUMENT_INGESTION"
            is_retryable = False

        flags["valuation_audit_status"] = "PROVISIONAL_UNVERIFIED_SOURCE"
        flags["financial_currency"] = fin_curr
        flags["partial_reason"] = partial_reason
        flags["missing_components"] = missing_components
        flags["retryable"] = is_retryable

        fund_metrics = {
            "pe_ratio": pe_ratio,
            "pbv_ratio": pbv_ratio,
            "roe_pct": roe_pct,
            "roa_pct": roa_pct,
            "der_ratio": der_ratio,
            "der_pure_debt_ratio": der_pure_ratio,
            "pure_debt": latest_rep.get("pure_debt") if fin_reports else None,
            "long_term_debt": latest_rep.get("long_term_debt") if fin_reports else None,
            "current_debt": latest_rep.get("current_debt") if fin_reports else None,
            "capital_lease_obligations": latest_rep.get("capital_lease_obligations") if fin_reports else None,
            "liabilities_to_equity_ratio": liab_to_eq_ratio,
            "net_profit_margin_pct": npm_pct,
            "operating_margin_pct": opm_pct,
            "revenue_growth_yoy_pct": rev_growth_yoy,
            "net_income_growth_yoy_pct": ni_growth_yoy,
            "dividend_yield_pct": div_yield_pct,
            "dividend_cash_amount": div_cash_amount,
            "latest_dividend_date": latest_div_date,
            "eps": eps,
            "bvps": bvps,
            "shares_outstanding": shares_out,
            "weighted_average_shares": weighted_avg_shares,
            "sector_template": sector_template,
            "fundamental_status": fund_status,
            "metric_sources": {
                "pe_source": pe_source,
                "pbv_source": pbv_source,
                "der_source": der_source,
                "eps_source": eps_source,
                "bvps_source": bvps_source,
                "dividend_source": div_source,
            },
            "data_quality_flags": flags,
        }

        duration_ms = int((time.time() - t0) * 1000)

        return {
            "success": True,
            "ticker": clean_ticker,
            "duration_ms": duration_ms,
            "bars_count": len(all_bars),
            "recent_bars": all_bars,
            "actions": actions,
            "financial_reports": fin_reports,
            "technical_metrics": tech_metrics,
            "fundamental_metrics": fund_metrics,
        }

    except Exception as e:
        return {
            "success": False,
            "ticker": clean_ticker,
            "error_type": "COLLECTION_EXCEPTION",
            "message": str(e),
        }

if __name__ == "__main__":
    t = sys.argv[1] if len(sys.argv) > 1 else "BBCA"
    res = fetch_and_process_ticker(t)
    print(f"Ticker: {res.get('ticker')} | Success: {res.get('success')} | Bars: {res.get('bars_count')}")
    if res.get("success"):
        tm = res["technical_metrics"]
        fm = res["fundamental_metrics"]
        print(f"Tech: Close={tm['close_price']} SMA20={tm['sma_20']} SMA50={tm['sma_50']} SMA200={tm['sma_200']} RSI14={tm['rsi_14']} Trend={tm['technical_trend']}")
        print(f"Fund: PER={fm['pe_ratio']} PBV={fm['pbv_ratio']} ROE={fm['roe_pct']}% DER={fm['der_ratio']} Template={fm['sector_template']} EPS={fm['eps']} BVPS={fm['bvps']}")
    else:
        print("Error:", res.get("message"))
