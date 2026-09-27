// Package crypt decrypts values Laravel stored with Crypt::encryptString (AES-256-CBC).
package crypt

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/hmac"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
)

// ErrDecrypt means the ciphertext is malformed, tampered with, or was encrypted with another key.
var ErrDecrypt = errors.New("decrypt: invalid ciphertext")

// Key holds the raw 32-byte Laravel APP_KEY.
type Key struct {
	raw []byte
}

// ParseKey accepts a Laravel APP_KEY (`base64:...`) and rejects any other shape.
func ParseKey(appKey string) (Key, error) {
	appKey = strings.TrimSpace(appKey)
	const prefix = "base64:"
	if !strings.HasPrefix(appKey, prefix) {
		return Key{}, fmt.Errorf("crypto: APP_KEY must use %q format", prefix)
	}
	raw, err := base64.StdEncoding.Strict().DecodeString(strings.TrimPrefix(appKey, prefix))
	if err != nil {
		return Key{}, fmt.Errorf("crypto: decode APP_KEY: %w", err)
	}
	if len(raw) != 32 {
		return Key{}, fmt.Errorf("crypto: APP_KEY must decode to 32 bytes, got %d", len(raw))
	}
	return Key{raw: append([]byte(nil), raw...)}, nil
}

type envelope struct {
	IV    string `json:"iv"`
	Value string `json:"value"`
	MAC   string `json:"mac"`
	Tag   string `json:"tag"`
}

// DecryptString verifies the HMAC and decrypts one Laravel Crypt payload.
func (k Key) DecryptString(ciphertext string) (string, error) {
	if len(k.raw) != 32 {
		return "", ErrDecrypt
	}
	payload, err := base64.StdEncoding.Strict().DecodeString(strings.TrimSpace(ciphertext))
	if err != nil {
		return "", ErrDecrypt
	}
	var env envelope
	if err := json.Unmarshal(payload, &env); err != nil || env.IV == "" || env.Value == "" || env.MAC == "" || env.Tag != "" {
		return "", ErrDecrypt
	}
	mac := hmac.New(sha256.New, k.raw)
	_, _ = mac.Write([]byte(env.IV + env.Value))
	expected, err := hex.DecodeString(env.MAC)
	if err != nil || len(expected) != sha256.Size || !hmac.Equal(mac.Sum(nil), expected) {
		return "", ErrDecrypt
	}
	iv, err := base64.StdEncoding.Strict().DecodeString(env.IV)
	if err != nil || len(iv) != aes.BlockSize {
		return "", ErrDecrypt
	}
	value, err := base64.StdEncoding.Strict().DecodeString(env.Value)
	if err != nil || len(value) == 0 || len(value)%aes.BlockSize != 0 {
		return "", ErrDecrypt
	}
	block, err := aes.NewCipher(k.raw)
	if err != nil {
		return "", ErrDecrypt
	}
	plain := make([]byte, len(value))
	cipher.NewCBCDecrypter(block, iv).CryptBlocks(plain, value)
	padding := int(plain[len(plain)-1])
	if padding == 0 || padding > aes.BlockSize || padding > len(plain) {
		return "", ErrDecrypt
	}
	for _, b := range plain[len(plain)-padding:] {
		if subtle.ConstantTimeByteEq(b, byte(padding)) != 1 {
			return "", ErrDecrypt
		}
	}
	return string(plain[:len(plain)-padding]), nil
}

// UnwrapSecret decrypts {"encrypted":"..."} or returns plaintext JSON when it was never encrypted.
func (k Key) UnwrapSecret(stored string) (string, error) {
	stored = strings.TrimSpace(stored)
	if stored == "" {
		return "", nil
	}
	var wrap struct {
		Encrypted string `json:"encrypted"`
	}
	if json.Unmarshal([]byte(stored), &wrap) == nil && wrap.Encrypted != "" {
		return k.DecryptString(wrap.Encrypted)
	}
	return stored, nil
}
