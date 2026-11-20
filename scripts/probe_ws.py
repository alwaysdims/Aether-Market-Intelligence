import asyncio
import os
import json
import websockets

async def probe():
    # Read API key from env or php artisan config
    key = os.environ.get("IDX_EDGE_API_KEY", "")
    if not key:
        import subprocess
        cmd = ["php", "artisan", "tinker", "--execute=echo config('services.idx_edge.api_key');"]
        proc = subprocess.run(cmd, cwd="dashboard", capture_output=True, text=True)
        key = proc.stdout.strip()
    
    print(f"Connecting with API Key length: {len(key)}")
    uri = "wss://stock.arjum.com/ws/running-trade"
    headers = {"X-API-Key": key}
    
    try:
        async with websockets.connect(uri, additional_headers=headers) as ws:
            print("Connected successfully! Waiting for messages...")
            for i in range(10):
                msg = await asyncio.wait_for(ws.recv(), timeout=15)
                data = json.loads(msg)
                print(f"Message {i+1} type: {data.get('type')} keys: {list(data.keys())}")
                if i < 3:
                    print(f"Sample {i+1}: {str(data)[:300]}")
    except Exception as e:
        print(f"Connection error: {type(e).__name__}: {e}")

if __name__ == "__main__":
    asyncio.run(probe())
