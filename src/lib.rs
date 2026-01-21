use std::fs;
use zed_extension_api::{self as zed, LanguageServerId, Result};

const LSP_SCRIPT: &str = include_str!("laravel-lsp.php");
const LSP_FILENAME: &str = "laravel-lsp.php";

struct LaravelExtension {
    script_path: Option<String>,
}

impl LaravelExtension {
    fn ensure_script_exists(&mut self) -> Result<String> {
        if let Some(path) = &self.script_path {
            return Ok(path.clone());
        }

        fs::write(LSP_FILENAME, LSP_SCRIPT)
            .map_err(|e| format!("Failed to write LSP script: {e}"))?;

        let path = std::env::current_dir()
            .map_err(|e| format!("Failed to get current directory: {e}"))?
            .join(LSP_FILENAME)
            .to_string_lossy()
            .into_owned();

        self.script_path = Some(path.clone());
        Ok(path)
    }
}

impl zed::Extension for LaravelExtension {
    fn new() -> Self {
        Self { script_path: None }
    }

    fn language_server_command(
        &mut self,
        _language_server_id: &LanguageServerId,
        _worktree: &zed::Worktree,
    ) -> Result<zed::Command> {
        let script_path = self.ensure_script_exists()?;

        Ok(zed::Command {
            command: "/usr/bin/env".into(),
            args: vec!["php".into(), script_path],
            env: Default::default(),
        })
    }
}

zed::register_extension!(LaravelExtension);
