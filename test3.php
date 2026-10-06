<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Underground Profile Test - Version 2</title>
    <style>
        body { margin:0; padding:0; background:#0a0a0a; font-family:'Courier New', monospace; }
        .container {
            max-width: 1000px;
            margin: 40px auto;
            border: 2px solid #400000;
            box-shadow: 0 0 30px rgba(150,0,0,0.4);
        }
        .header {
            background: #1a0000;
            padding: 20px;
            text-align: center;
        }
        .header h1 { color: #990000; margin: 0; }
        
        .controls {
            padding: 20px;
            background: #111;
            text-align: center;
        }
        input {
            width: 65%;
            padding: 15px;
            background: #1f1f1f;
            border: 1px solid #500000;
            color: white;
            font-size: 16px;
        }
        button {
            padding: 15px 30px;
            background: #800000;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
        
        .preview {
            height: 560px;
            background-size: cover;
            background-position: center;
            position: relative;
            padding: 40px;
        }
        
        .profile-box {
            background: rgba(0,0,0,0.65);
            border: 1px solid #600000;
            padding: 25px;
            max-width: 460px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>UNDERGROUND PROFILE TEST - VERSION 2</h1>
        </div>
        
        <div class="controls">
            <input type="text" id="prompt" placeholder="Describe your profile (ex: dark gothic with skulls)" />
            <button onclick="generateTheme()">Generate</button>
        </div>
        
        <div class="preview" id="preview">
            <div class="profile-box">
                <h2 id="theme-name" style="color:#cc0000;">YOUR PROFILE</h2>
                <p id="result-text">Your custom background will appear here...</p>
            </div>
        </div>
    </div>

    <script>
        function generateTheme() {
            const prompt = document.getElementById('prompt').value.toLowerCase();
            const preview = document.getElementById('preview');
            const resultText = document.getElementById('result-text');
            const themeName = document.getElementById('theme-name');
            
            let bgImage = "linear-gradient(#110000, #000000)";
            let theme = "DARK VOID";
            
            if (prompt.includes('gothic') || prompt.includes('skull')) {
                bgImage = "url('https://picsum.photos/id/1015/2000/1200')";
                theme = "GOTHIC REALM";
            } else if (prompt.includes('red') || prompt.includes('blood')) {
                bgImage = "url('https://picsum.photos/id/201/2000/1200')";
                theme = "BLOOD RITUAL";
            } else if (prompt.includes('cyber') || prompt.includes('neon')) {
                bgImage = "url('https://picsum.photos/id/160/2000/1200')";
                theme = "NEON ABYSS";
            }
            
            preview.style.backgroundImage = bgImage;
            themeName.textContent = theme;
            resultText.innerHTML = `Theme: <strong>${prompt}</strong>`;
        }
    </script>
</body>
</html>