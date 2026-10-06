<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Underground Profile Test</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background: #0a0a0a;
            color: #ddd;
            font-family: 'Courier New', monospace;
        }
        .container {
            max-width: 1000px;
            margin: 40px auto;
            background: #111;
            border: 2px solid #400000;
            box-shadow: 0 0 30px rgba(150, 0, 0, 0.3);
        }
        .header {
            background: linear-gradient(#300000, #100000);
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid #600000;
        }
        .header h1 { margin: 0; color: #990000; }
        
        .controls {
            padding: 20px;
            background: #1a1a1a;
            text-align: center;
        }
        
        input {
            width: 60%;
            padding: 14px;
            background: #222;
            border: 1px solid #500000;
            color: #ddd;
            font-size: 16px;
        }
        
        button {
            padding: 14px 30px;
            background: #800000;
            color: white;
            border: none;
            font-size: 16px;
            cursor: pointer;
        }
        
        button:hover {
            background: #c00000;
        }
        
        .preview {
            padding: 40px;
            min-height: 500px;
            position: relative;
            background-size: cover;
            background-position: center;
            transition: all 0.6s;
        }
        
        .profile-box {
            background: rgba(0, 0, 0, 0.75);
            border: 1px solid #600000;
            padding: 25px;
            max-width: 500px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>UNDERGROUND PROFILE TEST 222</h1>
            <p>Describe your vibe — AI will create your profile look</p>
        </div>
        
        <div class="controls">
            <input type="text" id="prompt" placeholder="Example: dark gothic with skulls and red mist" />
            <button onclick="generateTheme()">Generate Profile</button>
        </div>
        
        <div class="preview" id="preview">
            <div class="profile-box">
                <h2 id="theme-name" style="color:#990000;">YOUR PROFILE</h2>
                <p id="result-text">Click "Generate Profile" and describe your style...</p>
            </div>
        </div>
    </div>

    <script>
        function generateTheme() {
            const prompt = document.getElementById('prompt').value.toLowerCase();
            const preview = document.getElementById('preview');
            const themeName = document.getElementById('theme-name');
            const resultText = document.getElementById('result-text');
            
            let background = "linear-gradient(#110000, #000000)";
            let theme = "DARK REALM";
            
            if (prompt.includes('gothic') || prompt.includes('skull')) {
                background = "linear-gradient(rgba(20,0,0,0.9), rgba(5,0,0,0.95)), url('https://picsum.photos/id/1015/1200/800')";
                theme = "GOTHIC REALM";
            } else if (prompt.includes('red') || prompt.includes('blood')) {
                background = "#300000";
                theme = "BLOOD RED";
            } else if (prompt.includes('cyber') || prompt.includes('neon')) {
                background = "linear-gradient(#001122, #000000)";
                theme = "NEON VOID";
            } else if (prompt.includes('green')) {
                background = "linear-gradient(#001100, #000000)";
                theme = "TOXIC GREEN";
            }
            
            preview.style.background = background;
            themeName.textContent = theme;
            resultText.innerHTML = `Background generated for: <strong>${prompt}</strong>`;
        }
    </script>
</body>
</html>