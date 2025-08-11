<?php
// Config
define("Site_Name", "File Upload");
define("Allowed_File_Types", "*/*"); // default allows all files. This is safe as files are not saved as original extensions so RCE is not possible. (This must be a valid HTML5 file input accept attribute value)
define("Uploaded_Files_Dir", "./uploads"); // no trailing slash

// To make this work setup a cronjob to call this script with ?cron=true every 24 hours (or less)
define("File_Lifetime", "forever"); // How long to store uploaded files (in seconds or type forever to never delete) 
define("Max_File_Size", "1073741824"); // Max file size for uploads (in bytes)
// End config
// Code is below. You can edit it to easily customize the UI and other parts of the page.

if(!file_exists(Uploaded_Files_Dir)){
    try {
        mkdir(Uploaded_Files_Dir);
    } catch (\Throwable $th) {
        die("Something went wrong. Could not create Uploaded_Files_Dir.");
    }
}

if(isset($_GET['cron'])){
    if($_GET['cron']=='true'){
        if (File_Lifetime !== "forever") {
            foreach (scandir(Uploaded_Files_Dir) as $file) {
                if ($file === '.' || $file === '..') continue; 
                $filePath = Uploaded_Files_Dir . '/' . $file;
                if (is_file($filePath)) {
                    $age = time() - filectime($filePath); 
                    if ($age > File_Lifetime) {
                        unlink($filePath); 
                    }
                }
            }
        }
    }
}


function getUserIP() {
  if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
    return $_SERVER['HTTP_CF_CONNECTING_IP'];
  }
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    return trim($ips[0]);
  }
  if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
    return $_SERVER['HTTP_CLIENT_IP'];
  }
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}



if(isset($_GET['upload']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])){
    $file = $_FILES['file'];
    if($file['size'] > Max_File_Size){
        http_response_code(400);
        die();
    }

    $accept = Allowed_File_Types;

    $fileName = $file['name'];
    $fileType = $file['type'];

    $acceptItems = array_map('trim', explode(',', $accept));
    $valid = false;

    foreach ($acceptItems as $item) {
        if ($item === '*/*') {
            $valid = true;
            break;
        }
        if (str_starts_with($item, '.')) {
            // check extension
            if (str_ends_with(strtolower($fileName), strtolower($item))) {
                $valid = true;
                break;
            }
        } elseif (str_ends_with($item, '/*')) {
            // check mime type prefix
            $prefix = substr($item, 0, -2);
            if (str_starts_with($fileType, $prefix . '/')) {
                $valid = true;
                break;
            }
        } else {
            // check exact mime type
            if ($fileType === $item) {
                $valid = true;
                break;
            }
        }
    }

    if (!$valid) {
        http_response_code(400);
        die();
    }
    $fileId = time() . uniqid(random_int(0,9999),true);
    echo $fileId;
    $target = Uploaded_Files_Dir . '/' . $fileId . '.data';
    file_put_contents(Uploaded_Files_Dir.'/'.$fileId.'.json', json_encode(['originalName' => basename($file['name']), 'fromIp' => getUserIP(), 'at' => time()]));

    if (move_uploaded_file($file['tmp_name'], $target)) {
        http_response_code(200);
        die();
    } else {
        http_response_code(500);
        die();
    }
} else {
    if(isset($_GET['upload']) || $_SERVER['REQUEST_METHOD'] === 'POST'){
        http_response_code(400);
        die();
    }
}

if(isset($_GET['download'])){
    $jsonPath = Uploaded_Files_Dir . '/' . basename($_GET['download']) . '.json';
    if (file_exists($jsonPath)) {
        $dataPath = Uploaded_Files_Dir . '/' . basename($_GET['download']) . '.data';
        if (file_exists($dataPath)) {
            $fileInfo = json_decode(file_get_contents($jsonPath), true);
            $fileName = $fileInfo['originalName'] ?? 'file';

            $fileSize = filesize($dataPath);

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $dataPath);
            finfo_close($finfo);
            if (!$mimeType) {
                $mimeType = 'application/octet-stream';
            }

            $start = 0;
            $end = $fileSize - 1;
            $length = $fileSize;

            if (isset($_SERVER['HTTP_RANGE'])) {
                if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
                    $start = $matches[1] === '' ? 0 : intval($matches[1]);
                    $end = $matches[2] === '' ? $end : intval($matches[2]);

                    if ($start < 0 || $end < 0 || $start > $end || $start >= $fileSize) {
                        header('HTTP/1.1 416 Range Not Satisfiable');
                        header("Content-Range: bytes */$fileSize");
                        exit;
                    }

                    if ($end > $fileSize - 1) $end = $fileSize - 1;
                    $length = $end - $start + 1;

                    header('HTTP/1.1 206 Partial Content');
                    header("Content-Range: bytes $start-$end/$fileSize");
                }
            }


            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: filename="' . basename($fileName) . '"');
            header('Content-Length: ' . $length);
            header('Accept-Ranges: bytes');

            $fp = fopen($dataPath, 'rb');
            fseek($fp, $start);
            $bufferSize = 8192;
            $bytesLeft = $length;

            while ($bytesLeft > 0 && !feof($fp)) {
                $readLength = $bytesLeft > $bufferSize ? $bufferSize : $bytesLeft;
                echo fread($fp, $readLength);
                flush();
                $bytesLeft -= $readLength;
            }
            fclose($fp);
            exit;
        }else{
            http_response_code(400);
            die();
        }
    }else{
        http_response_code(400);
        die();
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php
        if(isset($_GET['viewFile'])){
                    $jsonPath = Uploaded_Files_Dir . '/' . basename($_GET['viewFile']) . '.json';
                    if (file_exists($jsonPath)) {
                        
                        function formatFileSize($filePath) {
                            if (!file_exists($filePath)) return 'file not found';

                            $bytes = filesize($filePath);
                            if ($bytes === false) return 'error';

                            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                            $i = 0;

                            while ($bytes >= 1024 && $i < count($units) - 1) {
                                $bytes /= 1024;
                                $i++;
                            }

                            return round($bytes, 2) . $units[$i];
                        }
                        $desc = formatFileSize(Uploaded_Files_Dir.'/'.basename($_GET['viewFile']).'.data');
                        $desc = $desc. " | Uploaded ";
                        function getFileCreationAgo($filePath) {
                            if (!file_exists($filePath)) return 'file not found';

                            $time = filectime($filePath);
                            if ($time === false) return 'error';

                            $diff = time() - $time;
                            if ($diff < 5) return 'just now';

                            $units = [
                                31536000 => 'year',   
                                2592000  => 'month', 
                                604800   => 'week',   
                                86400    => 'day',
                                3600     => 'hour',
                                60       => 'minute',
                                1        => 'second'
                            ];

                            foreach ($units as $secs => $name) {
                                if ($diff >= $secs) {
                                    $val = floor($diff / $secs);
                                    return $val . ' ' . $name . ($val > 1 ? 's' : '') . ' ago';
                                }
                            }
                        }

                        $desc = $desc . getFileCreationAgo(Uploaded_Files_Dir.'/'.basename($_GET['viewFile']).'.data');
                        echo '<title>Uploaded file - '. json_decode(file_get_contents($jsonPath), true)['originalName'].'</title>';
                    } else {
                        $desc = "The requested file could not be found.";
                        echo '<title>The requested file could not be found.</title>';
                    }
                    echo '  <meta name="description" content="'.$desc.'">';
                    
                }else{
                    echo '<meta name="description" content="Upload and share your files quickly and securely. No hassle, instant access.">';
                    echo '<title>'.Site_Name.' - Upload your file</title>';
                }
    ?>
    
    <meta name="keywords" content="file upload, file sharing, secure upload, fast upload, free file hosting, share files online">
    <meta name="robots" content="index, follow">
    <meta name="language" content="English">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@48,300,1,0&icon_names=upload" />
    <style>
        body{
            min-height:100vh;
            margin:0;
            min-width: 100vw;
            background-color: #062666ff;
            background-image: url(https://unsplash.com/photos/cPccYbPrF-A/download?ixid=M3wxMjA3fDB8MXx0b3BpY3x8Ym84alFLVGFFMFl8fHx8fDJ8fDE3NTQ5MjUzODJ8&force=true&w=1920);
            background-position: center center;
            background-repeat: no-repeat;
            background-size: cover;
            font-family: "Montserrat", sans-serif;
            font-optical-sizing: auto;
            font-style: normal;
            display: flex;
            flex-direction: column;
            flex-wrap: nowrap;
            align-content: center;
            justify-content: center;
            align-items: center;
        }
        span#imageOverlay {
            position: fixed;
            z-index: 0;
            color: #eee;
            font-weight: 400;
            background: #0000002e;
            padding: 5px;
            border-radius: 7px;
            bottom: 10px;
            left: 10px;
            font-size: 13px;
        }
        a[href] {
            color: #8adeffff;
            transition: color 0.2s;
        }
        a[href]:hover {
            color: #66d1fcff;
        }
        a[href]:active {
            color: #00b7ffff;
        }
        #upload-container{
            width:750px;
            height:550px;
            background-color: #eee;
            display:flex;
            overflow: hidden;
            border-radius: 12px;
        }
        #upload-container-left, #upload-container-right{
            width:50%;
            display:block;
            display: flex;
            flex-direction: column;
            flex-wrap: nowrap;
            align-content: center;
            justify-content: center;
            position: relative;
            align-items: center;
            <?php if(isset($_GET['viewFile'])): echo "display:none;"; endif; ?>
        }
        #upload-container-right{
            background-color: #dfdfdfff;
        }
        div#upload-container-left {
            line-height: 35.2px;
        }
        span#upload-instruction-icon {
            color: #1c81d8;
            user-select: none;
            font-size: 67px;
            margin-bottom: 26px;
        }
        span#upload-instruction-top {
            font-size: 24px;
        }
        span#upload-instruction-or {
            font-size: 20px;
            margin-bottom: 5px;
        }
        button#upload-instruction-button, #view-file-download-button {
            font-size: 20px;
            font-family: inherit;
            background-color: #1c81d8;
            color: #eee;
            border-width: 1px;
            border-style: solid;
            border-color: gray;
            border-radius: 7px;
            padding: 6px;
            padding-left: 70px;
            padding-right: 70px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        button#upload-instruction-button:hover, #view-file-download-button:hover {
            background-color: #1e8bebff;
        }
        button#upload-instruction-button:active, #view-file-download-button:active {
            background-color: #1890faff;
        }
        #hover-overlay{
            opacity:0;
            pointer-events: none;
            position: fixed;
            width:100vw;
            height:100vh;
            top:0;
            left:0;
            transition: opacity 0.4s;
            background-color: rgba(255, 255, 255, 0.5);
            backdrop-filter: blur(5px);
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-content: center;
            justify-content: center;
            align-items: center;
        }
        body.file-hover #hover-overlay{
            opacity:1;
            pointer-events: all;
        }
        span#hover-overlay-icon {
            font-size: 80px;
            color: #1c81d8;
        }
        #file-ext-icon {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: conic-gradient(#4caf50 calc(var(--progress) * 1%), #ddd 0);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-family: sans-serif;
            color: #333;
            font-size: 24px;
            position: relative;
        }
        #file-ext-icon::before {
            content: '';
            position: absolute;
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 50%;
        }
        #file-ext-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            transition: background 0.3s;
            background: conic-gradient(#acacac calc(var(--progress) * 1%), #dfdfdf 0);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            position: relative;
        }
        #file-ext-icon::before {
            content: var(--progressstring);
            position: absolute;
            width: 60px;
            height: 60px;
            background: #dfdfdf;
            border-radius: 50%;
            font-size: 14px;
            display: flex;
            flex-direction: column;
            flex-wrap: nowrap;
            text-overflow: ellipsis;
            overflow: hidden;
            white-space: nowrap;
            align-content: center;
            justify-content: center;
            align-items: center;
        }
        span#file-name {
            position: relative;
            opacity:0;
            transition: opacity 0.4s;
            padding-right: 20px; 
        }

        span#file-name::after {
            content: "✖"; 
            position: absolute;
            right: 0;
            top: 50%;
            transition: color 0.2s;
            transform: translateY(-50%);
            font-size: 14px;
            color: #888;
            cursor: pointer;
        }

        span#file-name:hover::after {
            color: #be5d5dff;
        }

        #viewFilePage{
            flex-direction: column;
            flex-wrap: nowrap;
            align-content: center;
            justify-content: center;
            align-items: center;
            width: 100%;
            <?php if (!isset($_GET['viewFile'])): ?>
                display:none;
            <?php else: ?>
                display:flex;
            <?php endif; ?>
        }

        span#view-file-ext-icon {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            border-width: 2px;
            border-style: solid;
            border-color: #1c81d8;
            color: #1c81d8;
            display: flex;
            flex-direction: column;
            flex-wrap: nowrap;
            align-content: center;
            justify-content: center;
            margin-bottom: 20px;
            align-items: center;
        }
        span#view-file-details {
            margin-top: 7px;
            font-size: 14px;
            color: #373737;
            margin-bottom: 27px;
        }
        #extra-info{
            position: absolute;
            bottom:5px;
            left:0px;
            width:100%;
            text-align: center;
            font-size:13px;
            color:gray;
        }
    </style>
</head>
<body>
    
    <div id='upload-container'>
        <div id='upload-container-left'>
            <span class="material-symbols-rounded" id='upload-instruction-icon'>
                upload
            </span>
            <span id='upload-instruction-top'>Drag and Drop file</span>
            <span id='upload-instruction-or'>or</span>
            <button id='upload-instruction-button' type='button' title='Browse' onclick='openFilePicker()'>Browse</button>
            <span id='extra-info'>Max file size: <?php
            function formatBytes($bytes, $decimals = 2) {
                $sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
                $factor = floor((strlen($bytes) - 1) / 3);
                return sprintf("%.{$decimals}f %s", $bytes / pow(1024, $factor), $sizes[$factor]);
            }
            function sizeToBytes($size) {
                $unit = strtolower(substr($size, -1));
                $num = (int)$size;

                switch($unit) {
                    case 'g': return $num * 1024 * 1024 * 1024;
                    case 'm': return $num * 1024 * 1024;
                    case 'k': return $num * 1024;
                    default: return (int)$size;
                }
            }
            $postMax = sizeToBytes(ini_get('post_max_size'));
            $uploadMax = sizeToBytes(ini_get('upload_max_filesize'));

            $minSize = min(Max_File_Size, $postMax, $uploadMax);

            echo formatBytes($minSize);
            ?></span>
        </div>
        <div id='upload-container-right'>
            <span id='file-ext-icon'></span>
            <span id='file-name'></span>
        </div>
        <div id='viewFilePage'>
            <?php
                $fileInfo = [];
                if (isset($_GET['viewFile'])) {
                    $jsonPath = Uploaded_Files_Dir . '/' . basename($_GET['viewFile']) . '.json';
                    if (file_exists($jsonPath)) {
                        $jsonData = file_get_contents($jsonPath); 
                        $fileInfo = json_decode($jsonData, true);
                        if (!file_exists(Uploaded_Files_Dir . '/' . basename($_GET['viewFile']) . '.data')) {
                            http_response_code(500);
                        }
                    } else {
                        http_response_code(400);
                    }
                }
            ?>
            <span id='view-file-ext-icon'><?php echo (isset($fileInfo['originalName']) && pathinfo($fileInfo['originalName'], PATHINFO_EXTENSION) ? '.' . pathinfo($fileInfo['originalName'], PATHINFO_EXTENSION) : '?');?></span>
            <?php
                $name = @$fileInfo['originalName'] ?? '';
                $maxLen = 50;

                if (strlen($name) > $maxLen) {
                    $start = substr($name, 0, 25);
                    $end = substr($name, -22);
                    $displayName = $start . '...' . $end;
                } else {
                    $displayName = $name;
                }
                ?>
            <span id='view-file-name' title="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($displayName) ?></span>

            <span id='view-file-details'><?php
                if(isset($_GET['viewFile'])){
                    $jsonPath = Uploaded_Files_Dir . '/' . basename($_GET['viewFile']) . '.json';
                    if (file_exists($jsonPath)) {
                        
                
                        echo formatFileSize(Uploaded_Files_Dir.'/'.basename($_GET['viewFile']).'.data');
                        echo " | Uploaded ";
                      

                        echo getFileCreationAgo(Uploaded_Files_Dir.'/'.basename($_GET['viewFile']).'.data');
                    } else {
                        echo "The requested file could not be found.";
                    }
                }
            ?></span>
            <?php
                if(isset($_GET['viewFile'])){
                    $dataPath = Uploaded_Files_Dir . '/' . basename($_GET['viewFile']) . '.data';
                    if (file_exists($dataPath)) {
                        echo "<button id='view-file-download-button' type='button' title='Download' onclick='downloadFile()'>Download</button>";
                    }
                }
            ?>
        </div>
    </div>

    <span id='imageOverlay'>
        Photo by 
        <a href="https://unsplash.com/@ricvath?utm_content=creditCopyText&utm_medium=referral&utm_source=unsplash">
            Richard Horvath</a>
         on 
        <a href="https://unsplash.com/photos/blue-and-white-heart-illustration-cPccYbPrF-A?utm_content=creditCopyText&utm_medium=referral&utm_source=unsplash">
            Unsplash</a>
    </span>

    <div id='hover-overlay' onclick="document.body.classList.remove('file-hover')">
        <span class="material-symbols-rounded" id='hover-overlay-icon'>
            upload
        </span>
    </div>
</body>
<script>
    function downloadFile(){
        <?php
            if(isset($_GET['viewFile'])){
                $dataPath = Uploaded_Files_Dir . '/' . basename($_GET['viewFile']) . '.data';
                if (file_exists($dataPath)) {
                    echo "window.location.href=`?download=".urlencode($_GET['viewFile'])."`;";
                }
            }
        ?>
    }

    function middleEllipsis(str, maxLength = 20) {
        if (str.length <= maxLength) return str;
        let half = Math.floor((maxLength - 3) / 2);
        return str.slice(0, half) + "..." + str.slice(-half);
    }
    let xhr = "";
    let icon = document.getElementById('file-ext-icon');
    let name = document.getElementById('file-name');
    let currentProgress = 0
    let myLastFileId = ""
    function handleFile(file) {
        name.style.opacity = 1
        icon.style = "--progress: 0; --progressstring: '0%';";
        name.innerText = middleEllipsis(file.name,35);
        let ext = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : 'Done';

        let formData = new FormData();
        formData.append('file', file);
        xhr = new XMLHttpRequest();
        xhr.overrideMimeType('text/plain; charset=x-user-defined');
        xhr.open('POST', '?upload');
        xhr.upload.onprogress = function (e) {
            if (e.lengthComputable) {
                let percent = Math.round((e.loaded / e.total) * 100);
                currentProgress = percent
                icon.style = `--progress: ${percent}; --progressstring: '${percent}%'`;
            }
        };

        xhr.onload = function () {
            if (xhr.status === 200) {
                let resp = xhr.responseText;
                myLastFileId = resp;
                window.location.href="?viewFile="+myLastFileId
                icon.style.setProperty('--progress', '100');
                icon.style.setProperty('--progressstring', ext);  
                icon.style = `--progress: 100; --progressstring: '${ext}'`;
                icon.style.background = '#7199ad'
            } else {
                icon.style = `--progress: 100; --progressstring: 'Error'`;
                icon.style.background = '#d43e3eff'
            }
        };

        xhr.send(formData);
    }


    function openFilePicker() {
        const input = document.createElement('input');
        input.type = 'file';
        input.style.display = 'none';
        document.body.appendChild(input);
        input.click();
        input.onchange = () => {
            const file = input.files[0]; 
            if (file) handleFile(file);
            document.body.removeChild(input);
        };
    }

    let dragCounter = 0;

    document.addEventListener('dragenter', e => {
        e.preventDefault();
        dragCounter++;
        document.body.classList.add('file-hover');
    });

    document.addEventListener('dragleave', e => {
        e.preventDefault();
        dragCounter--;
        if (dragCounter <= 0) {
            dragCounter = 0;
            document.body.classList.remove('file-hover');
        }
    });

    document.addEventListener('dragover', e => {
        e.preventDefault();
    });

    document.addEventListener('drop', e => {
        e.preventDefault();
        dragCounter = 0;
        document.body.classList.remove('file-hover');
        if (e.dataTransfer.files.length > 0) {
            handleFile(e.dataTransfer.files[0]);
        }
    });

    const fileNameEl = document.getElementById("file-name");

    fileNameEl.addEventListener("click", e => {
        const bounds = fileNameEl.getBoundingClientRect();
        if (e.clientX > bounds.right - 14) { 
            try{
                xhr.abort();
            }catch(e){}
            icon.style = ''
            name.style.opacity = 0
            currentProgress = 0
        }
    });
</script>
</html>
