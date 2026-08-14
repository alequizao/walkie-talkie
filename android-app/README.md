# Walkie Talkie — App Android (bloqueio de print)

App nativo que abre `https://publishdev.com.br/walkietalkie/` dentro de uma WebView,
com **`FLAG_SECURE`** ativado: **screenshots e gravação de tela ficam pretos** no
sistema todo. Já concede **microfone e câmera** ao WebRTC (voz e vídeo).

## Pré-requisitos
- **Android Studio** (Hedgehog ou mais novo) — já traz o JDK 17 e o Gradle.

## Como gerar o APK
1. Abra o **Android Studio** → *Open* → selecione a pasta `android-app/`.
2. Aguarde o *Gradle sync* (o Studio baixa o wrapper/SDK automaticamente).
3. Menu **Build → Build Bundle(s) / APK(s) → Build APK(s)**.
4. O APK sai em `app/build/outputs/apk/debug/app-debug.apk`.
5. Para distribuir: **Build → Generate Signed Bundle / APK** (release assinado).

### Pela linha de comando (se tiver o SDK + JDK 17)
```bash
cd android-app
# primeira vez, gere o wrapper (precisa do gradle instalado) ou use o do Android Studio:
gradle wrapper --gradle-version 8.2
./gradlew assembleDebug
```

## O que está configurado
- `MainActivity.java`: `FLAG_SECURE`, concessão de mic/câmera (`onPermissionRequest`),
  autoplay de áudio, histórico de navegação (botão voltar).
- `AndroidManifest.xml`: permissões INTERNET, RECORD_AUDIO, CAMERA, MODIFY_AUDIO_SETTINGS, WAKE_LOCK.
- `applicationId` / `namespace`: `br.com.publishdev.walkietalkie` (troque se quiser).
- Para mudar a URL, edite `APP_URL` em `MainActivity.java`.

## Observações
- **iOS não tem equivalente**: a Apple não permite bloquear screenshot em nenhum app.
- O ícone usa um padrão do sistema; troque em `res/mipmap-*` se quiser um próprio.
- `minSdk 24` (Android 7+). WebRTC funciona na WebView do sistema (Chromium).
