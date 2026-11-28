// Minimal Chrome extension types used in this project
declare namespace chrome {
  export namespace storage {
    export interface StorageArea {
      get(keys: string | string[] | object | null): Promise<object>;
      set(items: object): Promise<void>;
      remove(keys: string | string[]): Promise<void>;
      clear(): Promise<void>;
    }
    export const local: StorageArea;
    export const sync: StorageArea;
    export const managed: StorageArea;
    export const session: StorageArea;
    export function getBytesInUse(keys?: string | string[]): Promise<number>;
  }

  export namespace alarms {
    export interface Alarm {
      name: string;
      scheduledTime: number;
      periodInMinutes?: number;
    }
    export function create(name: string, alarmInfo: { delayInMinutes?: number; periodInMinutes?: number; when?: number }): Promise<void>;
    export function get(name?: string): Promise<Alarm | undefined>;
    export function getAll(): Promise<Alarm[]>;
    export function clear(name?: string): Promise<boolean>;
    export function clearAll(): Promise<boolean>;
    export const onAlarm: chrome.events.Event<(alarm: Alarm) => void>;
  }

  export namespace runtime {
    export function getManifest(): chrome.runtime.Manifest;
    export function getURL(path: string): string;
    export function sendMessage(message: any, options?: object): Promise<any>;
    export const onMessage: chrome.events.Event<(message: any, sender: chrome.runtime.MessageSender, sendResponse: (response?: any) => void) => void>;
    export const onInstalled: chrome.events.Event<(details: { reason: string; previousVersion?: string }) => void>;
    export const onStartup: chrome.events.Event<() => void>;
    export interface Manifest {
      manifest_version: number;
      name: string;
      version: string;
      description?: string;
      action?: { default_popup?: string; default_title?: string };
      background?: { service_worker?: string; type?: string };
      options_page?: string;
      permissions?: string[];
      optional_host_permissions?: string[];
      content_security_policy?: object;
    }
    export interface MessageSender {
      tab?: chrome.tabs.Tab;
      frameId?: number;
      id?: string;
      url?: string;
      tlsChannelId?: string;
    }
  }

  export namespace tabs {
    export interface Tab {
      id?: number;
      index: number;
      windowId: number;
      openerTabId?: number;
      selected: boolean;
      highlighted: boolean;
      active: boolean;
      pinned: boolean;
      audible?: boolean;
      discarded: boolean;
      autoDiscardable: boolean;
      mutedInfo: { muted: boolean; reason?: string };
      url?: string;
      title?: string;
      favIconUrl?: string;
      status?: string;
      incognito: boolean;
      width?: number;
      height?: number;
      sessionId?: string;
    }
    export function query(queryInfo: object): Promise<Tab[]>;
    export function executeScript(tabId: number, details: { code?: string; file?: string; allFrames?: boolean; frameId?: number; matchAboutBlank?: boolean; runAt?: string }): Promise<any[]>;
    export function sendMessage(tabId: number, message: any, options?: object): Promise<any>;
  }

  export namespace scripting {
    export interface ScriptInjection {
      target: { tabId: number; allFrames?: boolean; frameIds?: number[] };
      func?: () => any;
      args?: any[];
      files?: string[];
      world?: 'ISOLATED' | 'MAIN';
      injectImmediately?: boolean;
    }
    export interface ExecutionResult {
      frameId: number;
      result?: any;
      error?: any;
    }
    export function executeScript(injection: ScriptInjection): Promise<ExecutionResult[]>;
    export function insertCSS(injection: { target: { tabId: number; allFrames?: boolean; frameIds?: number[] }; css?: string; files?: string[]; origin?: 'AUTHOR' | 'USER' }): Promise<void>;
  }

  export namespace events {
    export interface Event<T extends Function> {
      addListener(callback: T): void;
      removeListener(callback: T): void;
      hasListener(callback: T): boolean;
      hasListeners(): boolean;
    }
  }

  export namespace action {
    export function setPopup(details: { tabId?: number; popup: string }): Promise<void>;
    export function getPopup(details: { tabId?: number }): Promise<string>;
    export function setTitle(details: { tabId?: number; title: string }): Promise<void>;
    export function setIcon(details: { tabId?: number; path?: string | object; imageData?: ImageData | object }): Promise<void>;
  }

  export namespace permissions {
    export interface Permissions {
      permissions?: string[];
      origins?: string[];
    }
    export function contains(permissions: Permissions): Promise<boolean>;
    export function request(permissions: Permissions): Promise<boolean>;
    export function remove(permissions: Permissions): Promise<boolean>;
    export function getAll(): Promise<Permissions>;
  }
}

declare const chrome: typeof import('./types/chrome');
