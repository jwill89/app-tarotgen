using System.Numerics;
using TarotGen.Plugin.Core;

namespace TarotGen.Plugin.Tests;

public class SpreadLayoutTests
{
    private static List<SpreadPosition> Positions(int spreadId) =>
        FakeServer.Fixture<List<Spread>>("spreads.json").Single(s => s.SpreadId == spreadId).Positions;

    [Fact]
    public void NoPositionsLeaveTheCanvasAsItIs() =>
        Assert.Equal(new SpreadLayout.Fit(1f, 50f, 50f), SpreadLayout.ComputeFit([]));

    [Fact]
    public void OneCardIsCentredAndZoomedNoMoreThanTwoAndAHalfTimes()
    {
        var fit = SpreadLayout.ComputeFit([new SpreadPosition { X = 30, Y = 70 }]);

        Assert.Equal(new SpreadLayout.Fit(2.5f, 30f, 70f), fit);
        Assert.Equal(new Vector2(50, 50), SpreadLayout.Project(fit, 30, 70));
    }

    [Fact]
    public void ARowOfThreeIsZoomedToFillTheCanvas()
    {
        var fit = SpreadLayout.ComputeFit(Positions(3));

        // 50 between the outer centres plus a card's width, to 92% of the canvas.
        Assert.Equal(100f / 61f * 0.92f, fit.Scale, 4);
        Assert.Equal((50f, 50f), (fit.Cx, fit.Cy));
        Assert.Equal(11f * fit.Scale, SpreadLayout.CardSizePct(fit).X, 4);
        Assert.Equal(11f * fit.Scale * 1.72f, SpreadLayout.CardSizePct(fit).Y, 4);
    }

    [Fact]
    public void ASpreadWiderThanTheCanvasIsNeverShrunk()
    {
        var fit = SpreadLayout.ComputeFit([new SpreadPosition { X = 0, Y = 50 }, new SpreadPosition { X = 100, Y = 50 }]);

        Assert.Equal(1f, fit.Scale);
    }

    [Theory]
    [InlineData(1)]
    [InlineData(3)]
    [InlineData(6)]
    [InlineData(9)]
    public void EveryCardOfAFittedSpreadLiesOnTheCanvas(int spreadId)
    {
        var positions = Positions(spreadId);
        var fit = SpreadLayout.ComputeFit(positions);
        var size = SpreadLayout.CardSizePct(fit);

        foreach (var p in positions)
        {
            var at = SpreadGeometry.Corners(SpreadLayout.Project(fit, p.X, p.Y), size.X, size.Y, p.Rotation);
            foreach (var corner in new[] { at.TopLeft, at.TopRight, at.BottomRight, at.BottomLeft })
            {
                Assert.InRange(corner.X, 0f, 100f);
                Assert.InRange(corner.Y, 0f, 100f);
            }
        }
    }
}

public class SpreadGeometryTests
{
    private static readonly Vector2 Centre = new(100, 100);

    [Fact]
    public void AnUprightCardHasSquareCorners()
    {
        var at = SpreadGeometry.Corners(Centre, 10, 20, 0);

        Assert.Equal(new CardCorners(new(95, 90), new(105, 90), new(105, 110), new(95, 110)), at);
    }

    [Fact]
    public void ACardTurnedAQuarterLiesOnItsSideClockwise()
    {
        var at = SpreadGeometry.Corners(Centre, 10, 20, 90);

        AssertNear(new Vector2(110, 95), at.TopLeft);
        AssertNear(new Vector2(110, 105), at.TopRight);
        AssertNear(new Vector2(90, 105), at.BottomRight);
        AssertNear(new Vector2(90, 95), at.BottomLeft);
    }

    [Fact]
    public void ACardTurnedHalfWayHasItsTopAtTheBottom()
    {
        var at = SpreadGeometry.Corners(Centre, 10, 20, 180);

        AssertNear(new Vector2(105, 110), at.TopLeft);
        AssertNear(new Vector2(95, 90), at.BottomRight);
    }

    [Fact]
    public void AReversedFaceIsTheImageTurnedHalfWay()
    {
        var upright = SpreadGeometry.FaceUvs(false);
        var reversed = SpreadGeometry.FaceUvs(true);

        Assert.Equal(new CardCorners(new(0, 0), new(1, 0), new(1, 1), new(0, 1)), upright);
        Assert.Equal(Vector2.One - upright.TopLeft, reversed.TopLeft);
        Assert.Equal(Vector2.One - upright.TopRight, reversed.TopRight);
        Assert.Equal(Vector2.One - upright.BottomRight, reversed.BottomRight);
        Assert.Equal(Vector2.One - upright.BottomLeft, reversed.BottomLeft);
    }

    [Theory]
    [InlineData(104, 108, 0, true)]
    [InlineData(108, 104, 0, false)]
    [InlineData(108, 104, 90, true)]
    [InlineData(104, 108, 90, false)]
    [InlineData(105, 110, 0, true)]
    [InlineData(150, 100, 45, false)]
    public void AClickLandsOnTheCardAsItIsTurned(float x, float y, int rotation, bool hit) =>
        Assert.Equal(hit, SpreadGeometry.HitTest(new Vector2(x, y), Centre, 10, 20, rotation));

    private static void AssertNear(Vector2 expected, Vector2 actual) =>
        Assert.True(Vector2.Distance(expected, actual) < 0.001f, $"expected {expected}, got {actual}");
}
