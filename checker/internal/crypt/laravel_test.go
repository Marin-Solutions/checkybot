package crypt

import (
	"encoding/base64"
	"os/exec"
	"strings"
	"testing"
)

func TestDecryptsALaravelCipherAndRejectsATamperedMAC(t *testing.T) {
	if _, err := exec.LookPath("php"); err != nil {
		t.Fatal("php is required to prove Laravel Crypt compatibility")
	}
	keyRaw := strings.Repeat("a", 32)
	keyB64 := base64.StdEncoding.EncodeToString([]byte(keyRaw))
	cmd := exec.Command("php", "-r", laravelEncryptScript)
	cmd.Env = append(cmd.Environ(), "KEY_B64="+keyB64, "PLAIN=header-secret")
	out, err := cmd.Output()
	if err != nil {
		t.Fatalf("php encrypt: %v", err)
	}
	cipher := strings.TrimSpace(string(out))
	key, err := ParseKey("base64:" + keyB64)
	if err != nil {
		t.Fatal(err)
	}
	plain, err := key.UnwrapSecret(`{"encrypted":"` + cipher + `"}`)
	if err != nil {
		t.Fatal(err)
	}
	if plain != "header-secret" {
		t.Fatalf("plain = %q", plain)
	}
	tampered := []byte(cipher)
	if tampered[len(tampered)-2] == 'A' {
		tampered[len(tampered)-2] = 'B'
	} else {
		tampered[len(tampered)-2] = 'A'
	}
	if _, err := key.DecryptString(string(tampered)); err == nil {
		t.Fatal("tampered ciphertext was accepted")
	}
}

const laravelEncryptScript = `
$key = base64_decode(getenv('KEY_B64'));
$plain = getenv('PLAIN');
$iv = random_bytes(16);
$pad = 16 - (strlen($plain) % 16);
$padded = $plain . str_repeat(chr($pad), $pad);
$raw = openssl_encrypt($padded, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
if ($raw === false) { fwrite(STDERR, "encrypt failed\n"); exit(1); }
$iv64 = base64_encode($iv);
$val64 = base64_encode($raw);
$mac = hash_hmac('sha256', $iv64.$val64, $key);
echo base64_encode(json_encode(['iv' => $iv64, 'value' => $val64, 'mac' => $mac, 'tag' => '']));
`
