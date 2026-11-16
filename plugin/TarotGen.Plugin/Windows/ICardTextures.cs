using Dalamud.Interface.Textures.TextureWraps;

namespace TarotGen.Plugin.Windows;

/// <summary>Card art as ImGui textures, for the reading views.</summary>
public interface ICardTextures
{
    /// <summary>The face of a card, or null while it loads.</summary>
    IDalamudTextureWrap? GetCard(int deckId, int cardId);
}
