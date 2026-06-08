<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Underground Profile Test - AI Demo</title>
    <style>
        body { margin:0; padding:0; background:#050505; font-family:'Courier New', monospace; color:#ddd; }
        .container { max-width: 1100px; margin: 30px auto; border: 2px solid #400000; background:#111; }
        .header { background: linear-gradient(#300000, #100000); padding: 25px; text-align:center; }
        .header h1 { color:#cc0000; margin:0; font-size:32px; letter-spacing:2px; }
        
        .controls { padding:25px; background:#1a1a1a; text-align:center; }
        input { width:70%; padding:16px; background:#222; border:1px solid #600000; color:white; font-size:17px; }
        button { padding:16px 35px; background:#800000; color:white; border:none; font-size:17px; cursor:pointer; margin-left:10px; }
        button:hover { background:#c00000; }

        .preview {
            height: 620px;
            background-size: cover;
            background-position: center;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .profile-box {
            background: rgba(10,0,0,0.75);
            border: 2px solid #800000;
            padding: 35px;
            width: 480px;
            text-align: center;
            box-shadow: 0 0 25px rgba(150,0,0,0.6);
        }
        
        .profile-box h2 { margin:0 0 15px 0; color:#ff4444; font-size:28px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>UNDERGROUND PROFILE AI DEMO</h1>
        </div>
        
        <div class="controls">
            <input type="text" id="prompt" placeholder="Describe your profile vibe..." />
            <button onclick="generateProfile()">GENERATE</button>
        </div>
        
        <div class="preview" id="preview">
            <div class="profile-box">
                <h2 id="theme-title">YOUR PROFILE</h2>
                <p id="status">Enter a description and click Generate</p>
            </div>
        </div>
    </div>

    <script>
        function generateProfile() {
            const prompt = document.getElementById('prompt').value.trim();
            if (!prompt) return;
            
            const preview = document.getElementById('preview');
            const status = document.getElementById('status');
            const title = document.getElementById('theme-title');
            
            status.textContent = "Generating AI background...";
            
            // Simulate AI generation delay
            setTimeout(() => {
                preview.style.backgroundImage = `url('https://picsum.photos/id/${Math.floor(Math.random()*300)}/2000/1200')`;
                title.textContent = prompt.toUpperCase();
                status.innerHTML = `<strong>AI Background Generated</strong><br>Based on: "${prompt}"`;
            }, 1200);
        }
    </script>
</body>
</html>