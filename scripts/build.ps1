$ErrorActionPreference='Stop'; $bun='D:\Users\PC\.bun\bin\bun.exe'
Push-Location 'E:\storeA\app\web'; try{& $bun 'node_modules/vite/bin/vite.js' build --config vite.config.js}finally{Pop-Location}
Push-Location 'E:\storeA\app\admin'; try{& $bun 'node_modules/vite/bin/vite.js' build --config vite.config.js}finally{Pop-Location}
