use std::fs;
use zed_extension_api::{self as zed, LanguageServerId, Result};

const LSP_SCRIPT: &str = include_str!("laravel-lsp.php");
const LSP_FILENAME: &str = "laravel-lsp.php";

struct LaravelViewExtension {
    script_path: Option<String>,
}

impl zed::Extension for LaravelViewExtension {
    fn new() -> Self {
        Self { script_path: None }
    }

    fn language_server_command(
        &mut self,
        _language_server_id: &LanguageServerId,
        _worktree: &zed::Worktree,
    ) -> Result<zed::Command> {
        let script_path = match &self.script_path {
            Some(path) => path.clone(),
            None => {
                fs::write(LSP_FILENAME, LSP_SCRIPT)
                    .map_err(|e| format!("failed to write LSP script: {e}"))?;

                let abs_path = std::env::current_dir()
                    .map_err(|e| format!("failed to get current dir: {e}"))?
                    .join(LSP_FILENAME)
                    .to_string_lossy()
                    .to_string();

                self.script_path = Some(abs_path.clone());
                abs_path
            }
        };

        Ok(zed::Command {
            command: "/usr/bin/env".into(),
            args: vec!["php".into(), script_path],
            env: Default::default(),
        })
    }
}

zed::register_extension!(LaravelViewExtension);
