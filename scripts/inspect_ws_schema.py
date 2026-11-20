import asyncio
import os
import json
import websockets

async def inspect():
    import subprocess
    cmd = ["php", "artisan", "tinker", "--execute=echo config('services.idx_edge.api_key');"]
    proc = subprocess.run(cmd, cwd="dashboard", capture_output=True, text=True)
    key = proc.stdout.strip()
    
    uri = "wss://stock.arjum.com/ws/running-trade"
    headers = {"X-API-Key": key}
    
    async with websockets.connect(uri, additional_headers=headers) as ws:
        msg = await ws.recv()
        snapshot = json.loads(msg)
        print("=== SNAPSHOT KEYS ===")
        print(snapshot.keys())
        print("\n=== SNAPSHOT['market'] ===")
        print(snapshot.get('market'))
        print("\n=== SNAPSHOT['top'][:3] ===")
        print(snapshot.get('top', [])[:3])
        print("\n=== SNAPSHOT['recent'][:3] ===")
        print(snapshot.get('recent', [])[:3])
        
        # Read next messages until we see top5 or other types
        seen_types = set(['snapshot'])
        for _ in range(50):
            m = await ws.recv()
            d = json.loads(m)
            t = d.get('type')
            if t not in seen_types:
                seen_types.add(t)
                print(f"\n=== NEW TYPE: {t} ===")
                print(json.dumps(d, indent=2)[:500])
            if t == 'trade' and _ < 5:
                print(f"\n=== TRADE SAMPLE {_} ===")
                print(json.dumps(d, indent=2))

if __name__ == "__main__":
    asyncio.run(inspect())
